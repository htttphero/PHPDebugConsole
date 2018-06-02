<?php
/**
 * This file is part of PHPDebugConsole
 *
 * @package   PHPDebugConsole
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2018 Brad Kent
 * @version   v2.2
 *
 * @link http://www.github.com/bkdotcom/PHPDebugConsole
 * @link https://developer.mozilla.org/en-US/docs/Web/API/console
 */

namespace bdk;

use bdk\ErrorHandler;
use bdk\ErrorHandler\ErrorEmailer;
use bdk\PubSub\SubscriberInterface;
use bdk\PubSub\Event;
use bdk\PubSub\Manager as EventManager;
use ReflectionClass;
use ReflectionMethod;

/**
 * Web-browser/javascript like console class for PHP
 *
 * @property Abstracter   $abstracter   lazy-loaded Abstracter obj
 * @property ErrorEmailer $errorEmailer lazy-loaded ErrorEmailer obj
 * @property MethodClear  $methodClear  lazy-loaded MethodClear obj
 * @property MethodTable  $methodTable  lazy-loaded MethodTable obj
 * @property Output       $output       lazy-loaded Output obj
 * @property Utf8         $utf8         lazy-loaded Utf8 obj
 */
class Debug
{

    private static $instance;
    protected $cfg = array();
    protected $data = array();
    protected $groupDepthRef;   // points to groupDepth or groupSummaryDepths[priority]
    protected $logRef;          // points to either log or logSummary[priority]
    protected $config;          // config instance
    public $errorHandler;
    public $eventManager;
    public $internal;
    public $utilities;

    const CLEAR_ALERTS = 1;
    const CLEAR_LOG = 2;
    const CLEAR_LOG_ERRORS = 4;
    const CLEAR_SUMMARY = 8;
    const CLEAR_SUMMARY_ERRORS = 16;
    const CLEAR_ALL = 31;
    const CLEAR_SILENT = 32;
    const COUNT_NO_INC = 1;
    const COUNT_NO_OUT = 2;
    const META = "\x00meta\x00";
    const VERSION = "2.2";

    /**
     * Constructor
     *
     * @param array        $cfg          config
     * @param EventManager $eventManager optional - specify EventManager instance
     *                                      will use new instance if not specified
     * @param ErrorHandler $errorHandler optional - specify ErrorHandler instance
     *                                      if not specified, will use singleton or new instance
     */
    public function __construct($cfg = array(), EventManager $eventManager = null, ErrorHandler $errorHandler = null)
    {
        $this->cfg = array(
            'collect'   => false,
            'file'      => null,            // if a filepath, will receive log data
            'key'       => null,
            'output'    => false,           // output the log?
            // which error types appear as "error" in debug console... all other errors are "warn"
            'errorMask' => E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR
                            | E_WARNING | E_USER_ERROR | E_RECOVERABLE_ERROR,
            'emailFunc' => 'mail',  // callable
            'emailLog'  => false,   // Whether to email a debug log.  (requires 'collect' to also be true)
                                    //
                                    //   false:   email will not be sent
                                    //   true or 'onError':   email will be sent (if log is not output)
                                    //   'always':  email sent regardless of whether error occured or log output
            'emailTo'   => !empty($_SERVER['SERVER_ADMIN'])
                ? $_SERVER['SERVER_ADMIN']
                : null,
            'logEnvInfo' => true,
            'logServerKeys' => array('REQUEST_URI','REQUEST_TIME','HTTP_HOST','SERVER_NAME','SERVER_ADDR','REMOTE_ADDR'),
            'onLog' => null,    // callable
        );
        $this->data = array(
            'alerts'            => array(), // array of alerts.  alerts will be shown at top of output when possible
            'counts'            => array(), // count method
            'entryCountInitial' => 0,       // store number of log entries created during init
            'groupDepth'        => array(0, 0), // 1st: ignores cfg['collect'], 2nd: when cfg['collect']
            'groupSummaryDepths' => array(),    // array(x,x) key'd by priority
            'groupSummaryStack' => array(), // array of priorities
                                            //   used to return to the previous summary when groupEnd()ing out of a summary
                                            //   this allows calling groupSummary() while in a groupSummary
            'log'               => array(),
            'logSummary'        => array(), // summary log entries subgrouped by priority
            'outputSent'        => false,
            'requestId'         => null,
            'runtime'           => array(),
            'timers' => array(      // timer method
                'labels' => array(
                    // label => array(accumulatedTime, lastStartedTime|null)
                    'debugInit' => array(
                        0,
                        isset($_SERVER['REQUEST_TIME_FLOAT'])
                            ? $_SERVER['REQUEST_TIME_FLOAT']
                            : \microtime(true)
                    ),
                ),
                'stack' => array(),
            ),
        );
        if (!isset(self::$instance)) {
            /*
               self::getInstance() will always return initial/first instance
            */
            self::$instance = $this;
            /*
                Only call spl_autoload_register on initial instance
                (even though re-registering function does't re-register)
            */
            \spl_autoload_register(array($this, 'autoloader'));
        }
        /*
            Initialize child objects
            (abstracter, errorEmailer, output, & utf8 are lazyloaded)
        */
        $this->eventManager = $eventManager
            ? $eventManager
            : new EventManager();
        if ($errorHandler) {
            $this->errorHandler = $errorHandler;
        } elseif (ErrorHandler::getInstance()) {
            $this->errorHandler = ErrorHandler::getInstance();
        } else {
            $this->errorHandler = new ErrorHandler($this->eventManager);
        }
        /*
            When collect=false, E_USER_ERROR will be sent to system_log without halting script
        */
        $this->errorHandler->setCfg('onEUserError', 'log');
        $this->utilities = new Debug\Utilities();
        $this->config = new Debug\Config($this, $this->cfg);    // cfg is passed by reference
        $this->internal = new Debug\Internal($this);
        /*
            Init config and properties
        */
        $this->config->setCfg($cfg);
        $this->data['requestId'] = $this->utilities->requestId();
        $this->setLogDest('log');
        /*
            Publish bootstrap event
        */
        $this->eventManager->publish('debug.bootstrap', $this);
        $this->data['entryCountInitial'] = \count($this->data['log']);
    }

    /**
     * Magic method... inaccessible method called.
     *
     * Treat as a custom method
     *
     * @param string $methodName Inaccessible method name
     * @param array  $args       Arguments passed to method
     *
     * @return void
     */
    public function __call($methodName, $args)
    {
        $this->appendLog(
            $methodName,
            $args,
            array('isCustomMethod' => true)
        );
    }

    /**
     * Magic method to allow us to call instance methods statically
     *
     * Prefix the instance method with an underscore ie
     *    \bdk\Debug::_log('logged via static method');
     *
     * @param string $methodName Inaccessible method name
     * @param array  $args       Arguments passed to method
     *
     * @return mixed
     */
    public static function __callStatic($methodName, $args)
    {
        $methodName = \ltrim($methodName, '_');
        if (!self::$instance) {
            new static();
        }
        return \call_user_func_array(array(self::$instance, $methodName), $args);
    }

    /**
     * Magic method to get inaccessible / undefined properties
     * Lazy load child classes
     *
     * @param string $property property name
     *
     * @return property value
     */
    public function __get($property)
    {
        $services = array(
            'abstracter' => function () {
                return new Debug\Abstracter($this->eventManager, $this->config->getCfgLazy('abstracter'));
            },
            'errorEmailer' => function () {
                return new ErrorEmailer($this->config->getCfgLazy('errorEmailer'));
            },
            'methodClear' => function () {
                return new Debug\MethodClear($this, $this->data);
            },
            'methodTable' => function () {
                return new Debug\MethodTable();
            },
            'output' => function () {
                $output = new Debug\Output($this, $this->config->getCfgLazy('output'));
                $this->eventManager->addSubscriberInterface($output);
                return $output;
            },
            'utf8' => function () {
                return new Debug\Utf8();
            },
        );
        if (isset($services[$property])) {
            $val = \call_user_func($services[$property]);
            $this->{$property} = $val;
            return $val;
        }
        $getter = 'get'.\ucfirst($property);
        if (\method_exists($this, $getter)) {
            return $this->{$getter}();
        }
        return null;
    }

    /*
        Debugging Methods
    */

    /**
     * Add an alert to top of log
     *
     * @param string  $message     message
     * @param string  $class       (danger), info, success, warning
     * @param boolean $dismissible (false)
     *
     * @return void
     */
    public function alert($message, $class = 'danger', $dismissible = false)
    {
        $this->appendLog(
            'alert',
            array($message),
            array(
                'class' => $class,
                'dismissible' => $dismissible,
            )
        );
    }

    /**
     * Log a message and stack trace to console if first argument is false.
     *
     * Only appends log when assertation fails
     *
     * @return void
     */
    public function assert()
    {
        $args = \func_get_args();
        $test = \array_shift($args);
        if (!$test) {
            if (!$args) {
                $callerInfo = $this->utilities->getCallerInfo();
                $args[] = 'assertation failed in '.$callerInfo['file'].' on line '.$callerInfo['line'];
            }
            $this->appendLog('assert', $args);
        }
    }

    /**
     * Clear the log
     *
     * This method executes even if `collect` is false
     *
     * @param integer $flags (self::CLEAR_LOG) specify what to clear (bitmask)
     *                         CLEAR_ALERTS
     *                         CLEAR_LOG (excluding warn & error)
     *                         CLEAR_LOG_ERRORS
     *                         CLEAR_SUMMARY (excluding warn & error)
     *                         CLEAR_SUMMARY_ERRORS
     *                         CLEAR_ALL
     *                         CLEAR_SILENT (don't add log entry)
     *
     * @return void
     */
    public function clear($flags = self::CLEAR_LOG)
    {
        $args = \func_get_args();
        $event = $this->methodClear->onLog(new Event($this, array(
            'method' => 'clear',
            'args' => array($flags),
            'meta' => $this->internal->getMetaVals($args),
        )));
        // even if cleared from within summary, lets's log this in primary log
        $this->setLogDest('log');
        $collect = $this->cfg['collect'];
        $this->cfg['collect'] = true;
        if ($event['log']) {
            $this->appendLog(
                $event['method'],
                $event['args'],
                $event['meta']
            );
        } elseif ($event['publish']) {
            /*
                Publish the debug.log event (regardless of cfg.collect)
                don't actually log
            */
            $this->eventManager->publish('debug.log', $event);
        }
        $this->cfg['collect'] = $collect;
        $this->setLogDest('auto');
    }

    /**
     * Log the number of times this has been called with the given label.
     *
     * If `label` is omitted, logs the number of times `count()` has been called at this particular line.
     *
     * @param mixed   $label label
     * @param integer $flags (optional)
     *                          A bitmask of
     *                          \bdk\Debug::COUNT_NO_INC : don't increment the counter
     *                          \bdk\Debug::COUNT_NO_OUT : don't output/log
     *
     * @return integer The count
     */
    public function count($label = null, $flags = 0)
    {
        $args = \func_get_args();
        if (\count($args) == 1 && \is_int($args[0])) {
            $label = null;
            $flags = $args[0];
        }
        $meta = array();
        if (isset($label)) {
            $dataLabel = (string) $label;
        } else {
            // determine calling file & line
            $callerInfo = $this->utilities->getCallerInfo();
            $meta = array(
                'file' => $callerInfo['file'],
                'line' => $callerInfo['line'],
            );
            $label = 'count';
            $dataLabel = $meta['file'].': '.$meta['line'];
        }
        if (!isset($this->data['counts'][$dataLabel])) {
            $this->data['counts'][$dataLabel] = 0;
        }
        if (!($flags & self::COUNT_NO_INC)) {
            $this->data['counts'][$dataLabel]++;
        }
        $count = $this->data['counts'][$dataLabel];
        if (!($flags & self::COUNT_NO_OUT)) {
            $this->appendLog(
                'count',
                array(
                    (string) $label,
                    $count,
                ),
                $meta
            );
        }
        return $count;
    }

    /**
     * Log an error message.
     *
     * @param mixed $label,... error message / values
     *
     * @return void
     */
    public function error()
    {
        $this->appendLog(
            'error',
            \func_get_args(),
            $this->internal->getErrorCaller()
        );
    }

    /**
     * Create a new inline group
     *
     * @param mixed $label,... label / values
     *
     * @return void
     */
    public function group()
    {
        $this->doGroup('group', \func_get_args());
    }

    /**
     * Create a new inline group
     *
     * @param mixed $label,... label / values
     *
     * @return void
     */
    public function groupCollapsed()
    {
        $this->doGroup('groupCollapsed', \func_get_args());
    }

    /**
     * Close current group
     *
     * @return void
     */
    public function groupEnd()
    {
        $groupDepthWas = $this->groupDepthRef;
        $this->groupDepthRef = array(
            \max(0, --$this->groupDepthRef[0]),
            $this->cfg['collect']
                ? \max(0, --$this->groupDepthRef[1])
                : $this->groupDepthRef[1],
        );
        if ($this->data['groupSummaryStack'] && $groupDepthWas[0] === 0) {
            \array_pop($this->data['groupSummaryStack']);
            $this->setLogDest('auto');
            /*
                Publish the debug.log event (regardless of cfg.collect)
                don't actually log
            */
            $this->eventManager->publish(
                'debug.log',
                $this,
                array(
                    'method' => 'groupEnd',
                    'args' => array(),
                    'meta' => array('closesSummary'=>true),
                )
            );
        } elseif ($this->cfg['collect'] && $groupDepthWas[1]) {
            $this->appendLog('groupEnd');
        }
        $errorCaller = $this->errorHandler->get('errorCaller');
        if ($errorCaller && isset($errorCaller['groupDepth']) && $this->getGroupDepth() < $errorCaller['groupDepth']) {
            $this->errorHandler->setErrorCaller(false);
        }
    }

    /**
     * Initiate the beginning of "summary" log entries
     *
     * Debug methods called while a groupSummary is open will appear at the top of the log
     * call groupEnd() to close summary
     *
     * groupSummary can be used multiple times
     * All groupSummary groups will appear together in a single group
     *
     * @param integer $priority (0) The higher the priority, the ealier it will appear.
     *
     * @return void
     */
    public function groupSummary($priority = 0)
    {
        $this->data['groupSummaryStack'][] = $priority;
        $this->setLogDest('summary');
        /*
            Publish the debug.log event (regardless of cfg.collect)
            don't actually log
        */
        $this->eventManager->publish(
            'debug.log',
            $this,
            array(
                'method' => 'groupSummary',
                'args' => array(),
                'meta' => array(
                    'priority' => $priority,
                ),
            )
        );
    }

    /**
     * Set ancestor groups to uncollapsed
     *
     * This will only occur if `cfg['collect']` is currently true
     *
     * @return void
     */
    public function groupUncollapse()
    {
        if (!$this->cfg['collect']) {
            return;
        }
        $entryKeys = \array_keys($this->internal->getCurrentGroups($this->logRef, $this->groupDepthRef[1]));
        foreach ($entryKeys as $key) {
            $this->logRef[$key][0] = 'group';
        }
        /*
            Publish the debug.log event (regardless of cfg.collect)
            don't actually log
        */
        $this->eventManager->publish(
            'debug.log',
            $this,
            array(
                'method' => 'groupUncollapse',
                'args' => array(),
                'meta' => array(),
            )
        );
    }

    /**
     * Log some informative information
     *
     * @return void
     */
    public function info()
    {
        $this->appendLog('info', \func_get_args());
    }

    /**
     * Log general information
     *
     * @return void
     */
    public function log()
    {
        $this->appendLog('log', \func_get_args());
    }

    /**
     * Output array as a table
     *
     * Accepts array of arrays or array of objects
     *
     * Arguments:
     *   1st encountered array (or traversable) is the data
     *   2nd encountered array (optional) specifies columns to output
     *   1st encountered string is a label/caption
     *
     * @return void
     */
    public function table()
    {
        if (!$this->cfg['collect']) {
            return;
        }
        $args = \func_get_args();
        $meta = $this->internal->getMetaVals($args);
        $event = $this->methodTable->onLog(new Event($this, array(
            'method' => 'table',
            'args' => $args,
            'meta' => $meta,
        )));
        $this->appendLog(
            $event['method'],
            $event['args'],
            $event['meta']
        );
    }

    /**
     * Start a timer identified by label
     *
     * Label passed
     *    if doesn't exist: starts timer
     *    if does exist: unpauses (does not reset)
     * Label not passed
     *    timer will be added to a no-label stack
     *
     * Does not append log.  Use timeEnd or timeGet to get time
     *
     * @param string $label unique label
     *
     * @return void
     */
    public function time($label = null)
    {
        if (isset($label)) {
            $timers = &$this->data['timers']['labels'];
            if (!isset($timers[$label])) {
                // new label
                $timers[$label] = array(0, \microtime(true));
            } elseif (!isset($timers[$label][1])) {
                // no microtime -> the timer is currently paused -> unpause
                $timers[$label][1] = \microtime(true);
            }
        } else {
            $this->data['timers']['stack'][] = \microtime(true);
        }
    }

    /**
     * Behaves like a stopwatch.. returns running time
     *    If label is passed, timer is "paused"
     *    If label is not passed, timer is removed from timer stack
     *
     * @param string         $label            unique label
     * @param string|boolean $returnOrTemplate string: "%label: %time"
     *                                         boolean:  If true, only return time, rather than log it
     * @param integer        $precision        rounding precision (pass null for no rounding)
     *
     * @return float|string (numeric)
     */
    public function timeEnd($label = null, $returnOrTemplate = false, $precision = 4)
    {
        if (\is_bool($label) || \strpos($label, '%time') !== false) {
            $returnOrTemplate = $label;
            $label = null;
        }
        $ret = $this->timeGet($label, true, null); // get non-rounded running time
        if (isset($label)) {
            if (isset($this->data['timers']['labels'][$label])) {
                $this->data['timers']['labels'][$label] = array(
                    $ret,  // store the new "running" time
                    null,  // "pause" the timer
                );
            }
        } else {
            $label = 'time';
            \array_pop($this->data['timers']['stack']);
        }
        if (\is_int($precision)) {
            // use number_format rather than round(), which may still run decimals-a-plenty
            $ret = \number_format($ret, $precision, '.', '');
        }
        $this->doTime($ret, $returnOrTemplate, $label);
        return $ret;
    }

    /**
     * Get the running time without stopping/pausing the timer
     *
     * @param string         $label            (optional) unique label
     * @param string|boolean $returnOrTemplate string: "%label: %time"
     *                                         boolean:  If true, only return time, rather than log it
     * @param integer        $precision        rounding precision (pass null for no rounding)
     *
     * @return float|string (numeric)
     */
    public function timeGet($label = null, $returnOrTemplate = false, $precision = 4)
    {
        if (\is_bool($label) || \strpos($label, '%time') !== false) {
            $precision = $returnOrTemplate;
            $returnOrTemplate = $label;
            $label = null;
        }
        $microT = 0;
        $ellapsed = 0;
        if (!isset($label)) {
            $label = 'time';
            if (!$this->data['timers']['stack']) {
                list($ellapsed, $microT) = $this->data['timers']['labels']['debugInit'];
            } else {
                $microT = \end($this->data['timers']['stack']);
            }
        } elseif (isset($this->data['timers']['labels'][$label])) {
            list($ellapsed, $microT) = $this->data['timers']['labels'][$label];
        }
        if ($microT) {
            $ellapsed += \microtime(true) - $microT;
        }
        if (\is_int($precision)) {
            // use number_format rather than round(), which may still run decimals-a-plenty
            $ellapsed = \number_format($ellapsed, $precision, '.', '');
        }
        $this->doTime($ellapsed, $returnOrTemplate, $label);
        return $ellapsed;
    }

    /**
     * Log a stack trace
     *
     * @return void
     */
    public function trace()
    {
        if (!$this->cfg['collect']) {
            return;
        }
        $backtrace = $this->errorHandler->backtrace();
        // toss "internal" frames
        for ($i = 1, $count = \count($backtrace)-1; $i < $count; $i++) {
            $frame = $backtrace[$i];
            $function = isset($frame['function']) ? $frame['function'] : '';
            if (!\preg_match('/^'.\preg_quote(__CLASS__).'(::|->)/', $function)) {
                break;
            }
        }
        $backtrace = \array_slice($backtrace, $i-1);
        // keep the calling file & line, but toss ->trace or ::_trace
        unset($backtrace[0]['function']);
        $this->appendLog('trace', array($backtrace));
    }

    /**
     * Log a warning
     *
     * @return void
     */
    public function warn()
    {
        $this->appendLog(
            'warn',
            \func_get_args(),
            $this->internal->getErrorCaller()
        );
    }

    /*
        "Non-Console" Methods
    */

    /**
     * Extend debug with a plugin
     *
     * @param SubscriberInterface $plugin object implementing SubscriberInterface
     *
     * @return void
     */
    public function addPlugin(SubscriberInterface $plugin)
    {
        $this->eventManager->addSubscriberInterface($plugin);
    }

    /**
     * Retrieve a configuration value
     *
     * @param string $path what to get
     *
     * @return mixed
     */
    public function getCfg($path = null)
    {
        return $this->config->getCfg($path);
    }

    /**
     * Advanced usage
     *
     * @param string $path path
     *
     * @return mixed
     */
    public function getData($path = null)
    {
        $path = \array_filter(\preg_split('#[\./]#', $path), 'strlen');
        $ret = $this->data;
        foreach ($path as $i => $k) {
            if (isset($ret[$k])) {
                $ret = $ret[$k];
                continue;
            }
            if ($i > 0) {
                if ($k == 'count') {
                    return \count($ret);
                }
                if ($k == 'end') {
                    $ret = \end($ret);
                    continue;
                }
            }
            return null;
        }
        return $ret;
    }

    /**
     * Returns the *Singleton* instance of this class.
     *
     * @param array $cfg optional config
     *
     * @return object
     */
    public static function getInstance($cfg = array())
    {
        if (!isset(self::$instance)) {
            // self::$instance set in __construct
            new static($cfg);
        } elseif ($cfg) {
            self::$instance->setCfg($cfg);
        }
        return self::$instance;
    }

    /**
     * "metafy" value/values
     *
     * accepts
     *   array()
     *   'cfg', option, value  (shortcut for setting single config value)
     *   key, value
     *   key                   (value defaults to true)
     *
     * @param mixed $args,... arguments
     *
     * @return array
     */
    public static function meta()
    {
        $args = \func_get_args();
        $count = \count($args);
        $args = \array_replace(array(null, null, null), $args);
        if (\is_array($args[0])) {
            $args[0]['debug'] = self::META;
            return $args[0];
        }
        if (!\is_string($args[0])) {
            return array('debug' => self::META);
        }
        if ($args[0] === 'cfg') {
            if (\is_array($args[1])) {
                return array(
                    'cfg' => $args[1],
                    'debug' => self::META,
                );
            }
            if (!\is_string($args[1])) {
                // invalid cfg key
                return array('debug' => self::META);
            }
            return array(
                'cfg' => array(
                    $args[1] => $count > 2
                        ? $args[2]
                        : true,
                ),
                'debug' => self::META,
            );
        }
        return array(
            $args[0] => $count > 1
                ? $args[1]
                : true,
            'debug' => self::META,
        );
    }

    /**
     * Publishes debug.output event and returns result
     *
     * @return string|null
     */
    public function output()
    {
        if (!$this->cfg['output']) {
            return null;
        }
        /*
            I'd like to put this outputAs setting bit inside Output::onOutput
            but, adding a debug.output subscriber from within a debug.output subscriber = fail
        */
        $outputAs = $this->output->getCfg('outputAs');
        if (\is_string($outputAs)) {
            $this->output->setCfg('outputAs', $outputAs);
        }
        $return = $this->eventManager->publish(
            'debug.output',
            $this,
            array('return'=>'')
        )['return'];
        $this->data['alerts'] = array();
        $this->data['counts'] = array();
        $this->data['groupDepth'] = array(0, 0);
        $this->data['groupSummaryDepths'] = array();
        $this->data['groupSummaryStack'] = array();
        $this->data['log'] = array();
        $this->data['logSummary'] = array();
        $this->data['outputSent'] = true;
        return $return;
    }

    /**
     * Remove plugin
     *
     * @param SubscriberInterface $plugin object implementing SubscriberInterface
     *
     * @return void
     */
    public function removePlugin(SubscriberInterface $plugin)
    {
        $this->eventManager->RemoveSubscriberInterface($plugin);
    }

    /**
     * Set one or more config values
     *
     * If setting a value via method a or b, old value is returned
     *
     * Setting/updating 'key' will also set 'collect' and 'output'
     *
     *    setCfg('key', 'value')
     *    setCfg('level1.level2', 'value')
     *    setCfg(array('k1'=>'v1', 'k2'=>'v2'))
     *
     * @param string|array $path   path
     * @param mixed        $newVal value
     *
     * @return mixed
     */
    public function setCfg($path, $newVal = null)
    {
        return $this->config->setCfg($path, $newVal);
    }

    /**
     * Advanced usage
     *
     *    setCfg('key', 'value')
     *    setCfg('level1.level2', 'value')
     *    setCfg(array('k1'=>'v1', 'k2'=>'v2'))
     *
     * @param string|array $path  path
     * @param mixed        $value value
     *
     * @return void
     */
    public function setData($path, $value = null)
    {
        if (\is_string($path)) {
            $path = \preg_split('#[\./]#', $path);
            $ref = &$this->data;
            foreach ($path as $k) {
                $ref = &$ref[$k];
            }
            $ref = $value;
        } else {
            $this->data = \array_merge($this->data, $path);
        }
        if (!$this->data['log']) {
            $this->data['groupDepth'] = array(0,0);
        }
        if (!$this->data['logSummary']) {
            $this->data['groupSummaryDepths'] = array();
            $this->data['groupSummaryStack'] = array();
            $this->setLogDest('log');
        }
    }

    /**
     * A wrapper for errorHandler->setErrorCaller
     *
     * @param array $caller (optional) null (default) determine automatically
     *                      empty value (false, "", 0, array()) clear
     *                      array manually set
     *
     * @return void
     */
    public function setErrorCaller($caller = null)
    {
        if ($caller === null) {
            $caller = $this->utilities->getCallerInfo(1);
            $caller = array(
                'file' => $caller['file'],
                'line' => $caller['line'],
            );
        }
        if ($caller) {
            // groupEnd will check depth and potentially clear errorCaller
            $caller['groupDepth'] = $this->getGroupDepth();
        }
        $this->errorHandler->setErrorCaller($caller);
    }

    /*
        Non-Public methods
    */

    /**
     * Debug class autoloader
     *
     * @param string $className classname to attempt to load
     *
     * @return void
     */
    protected function autoloader($className)
    {
        $className = \ltrim($className, '\\'); // leading backslash _shouldn't_ have been passed
        if (!\strpos($className, '\\')) {
            // className is not namespaced
            return;
        }
        $psr4Map = array(
            'bdk\\Debug\\' => __DIR__,
            'bdk\\PubSub\\' => __DIR__.'/../PubSub',
            'bdk\\ErrorHandler\\' => __DIR__.'/../ErrorHandler',
        );
        foreach ($psr4Map as $namespace => $dir) {
            if (\strpos($className, $namespace) === 0) {
                $rel = \substr($className, \strlen($namespace));
                $rel = \str_replace('\\', '/', $rel);
                require $dir.'/'.$rel.'.php';
                return;
            }
        }
        $classMap = array(
            'bdk\\ErrorHandler' => __DIR__.'/../ErrorHandler/ErrorHandler.php',
        );
        if (isset($classMap[$className])) {
            require $classMap[$className];
        }
    }

    /**
     * Store the arguments
     * if collect is false -> does nothing
     * otherwise:
     *   + abstracts values
     *   + publishes debug.log event
     *   + appends log (if event propagation not stopped)
     *
     * @param string $method error, info, log, warn, etc
     * @param array  $args   arguments passed to method
     * @param array  $meta   meta data
     *
     * @return void
     */
    protected function appendLog($method, $args = array(), $meta = array())
    {
        if (!$this->cfg['collect']) {
            return;
        }
        $cfgRestore = array();
        $meta = \array_merge($meta, $this->internal->getMetaVals($args));
        if (isset($meta['cfg'])) {
            $cfgRestore = $this->config->setCfg($meta['cfg']);
            unset($meta['cfg']);
        }
        foreach ($args as $i => $v) {
            if ($this->abstracter->needsAbstraction($v)) {
                $args[$i] = $this->abstracter->getAbstraction($v, $method);
            }
        }
        $event = $this->eventManager->publish(
            'debug.log',
            $this,
            array(
                'method' => $method,
                'args' => $args,
                'meta' => $meta,
            )
        );
        if ($cfgRestore) {
            $this->config->setCfg($cfgRestore);
        }
        if ($event->isPropagationStopped()) {
            return;
        }
        if ($method == 'alert') {
            $this->data['alerts'][] = array(
                $event->getValue('args')[0],
                $event->getValue('meta')
            );
        } else {
            $this->logRef[] = array(
                $event->getValue('method'),
                $event->getValue('args'),
                $event->getValue('meta')
            );
        }
    }

    /**
     * Append group or groupCollapsed to log
     *
     * @param string $method 'group' or 'groupCollapsed'
     * @param array  $args   arguments passed to group or groupCollapsed
     *
     * @return void
     */
    private function doGroup($method, $args)
    {
        $this->groupDepthRef[0]++;
        if (!$this->cfg['collect']) {
            return;
        }
        $this->groupDepthRef[1]++;
        /*
            Extract/remove meta so we can check if args are empty after extracting
        */
        $meta = $this->internal->getMetaVals($args);
        if (empty($args)) {
            // give a default label
            $caller = $this->utilities->getCallerInfo();
            if (isset($caller['class'])) {
                $args[] = $caller['class'].$caller['type'].$caller['function'];
                $meta['isMethodName'] = true;
            } elseif (isset($caller['function'])) {
                $args[] = $caller['function'];
            } else {
                $args[] = 'group';
            }
        }
        $this->appendLog($method, $args, $meta);
    }

    /**
     * Log timeEnd() and timeGet()
     *
     * @param float  $seconds          seconds
     * @param mixed  $returnOrTemplate false: log the time with default template (default)
     *                                  true: do not log
     *                                  string: log using passed template
     * @param string $label            label
     *
     * @return void
     */
    protected function doTime($seconds, $returnOrTemplate = false, $label = 'time')
    {
        if (\is_string($returnOrTemplate)) {
            $str = $returnOrTemplate;
            $str = \str_replace('%label', $label, $str);
            $str = \str_replace('%time', $seconds, $str);
        } elseif ($returnOrTemplate === true) {
            return;
        } else {
            $str = $label.': '.$seconds.' sec';
        }
        $this->appendLog('time', array($str));
    }

    /**
     * Calculate total group depth
     *
     * @return integer
     */
    protected function getGroupDepth()
    {
        $depth = $this->data['groupDepth'][0];
        foreach ($this->data['groupSummaryDepths'] as $groupDepth) {
            $depth += $groupDepth[0];
        }
        $depth += \count($this->data['groupSummaryStack']);
        return $depth;
    }

    /**
     * Set where appendLog appends to
     *
     * @param string $where ('auto'), 'log', or 'summary'
     *
     * @return void
     */
    private function setLogDest($where = 'auto')
    {
        if ($where == 'auto') {
            $where = $this->data['groupSummaryStack']
                ? 'summary'
                : 'log';
        }
        if ($where == 'log') {
            $this->logRef = &$this->data['log'];
            $this->groupDepthRef = &$this->data['groupDepth'];
        } else {
            $priority = \end($this->data['groupSummaryStack']);
            if (!isset($this->data['logSummary'][$priority])) {
                $this->data['logSummary'][$priority] = array();
                $this->data['groupSummaryDepths'][$priority] = array(0, 0);
            }
            $this->logRef = &$this->data['logSummary'][$priority];
            $this->groupDepthRef = &$this->data['groupSummaryDepths'][$priority];
        }
    }
}
