<?php

namespace bdk\Debug;

if (!defined('E_STRICT')) {
    define('E_STRICT', 2048);               // PHP 5.0.0
}
if (!defined('E_RECOVERABLE_ERROR')) {
    define('E_RECOVERABLE_ERROR', 4096);    // PHP 5.2.0
}
if (!defined('E_DEPRECATED')) {
    define('E_DEPRECATED', 8192);           // PHP 5.3.0
}
if (!defined('E_USER_DEPRECATED')) {
    define('E_USER_DEPRECATED', 16384);     // PHP 5.3.0
}

/**
 * Error handling methods
 */
class ErrorHandler
{

    protected $cfg = array();
    protected $data = array();
    protected $debug = null;
    protected $errTypes = array(
        E_ERROR             => 'Error',             // handled via shutdown function
        E_WARNING           => 'Warning',
        E_PARSE             => 'Parsing Error',     // handled via shutdown function
        E_NOTICE            => 'Notice',
        E_CORE_ERROR        => 'Core Error',        // handled via shutdown function
        E_CORE_WARNING      => 'Core Warning',      // handled?
        E_COMPILE_ERROR     => 'Compile Error',     // handled via shutdown function
        E_COMPILE_WARNING   => 'Compile Warning',   // handled?
        E_USER_ERROR        => 'User Error',
        E_USER_WARNING      => 'User Warning',
        E_USER_NOTICE       => 'User Notice',
        E_ALL               => 'E_ALL',             // listed here for completeness
        E_STRICT            => 'Runtime Notice (E_STRICT)',
        E_RECOVERABLE_ERROR => 'Fatal Error',
        E_DEPRECATED        => 'Deprecated',
        E_USER_DEPRECATED   => 'User Deprecated',
    );
    protected $errTypesGrouped = array(
        'deprecated'    => array( E_DEPRECATED, E_USER_DEPRECATED ),
        'error'         => array( E_USER_ERROR, E_RECOVERABLE_ERROR ),
        'notice'        => array( E_NOTICE, E_USER_NOTICE ),
        'strict'        => array( E_STRICT ),
        'warning'       => array( E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING ),
        'fatal'         => array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR ),
    );
    protected $registered = false;
    protected $oldErrorHandler = null;

    /**
     * Constructor
     *
     * @param array  $cfg   config
     * @param object $debug optional debug object
     */
    public function __construct($cfg = array(), $debug = null)
    {
        if ($debug) {
            $this->debug = $debug;
        } else {
            $this->debug = Debug::getInstance();
        }
        $this->cfg = array(
            // set onError to something callable, will receive a single boolean indicating whether error was fatal
            'onError'           => null,
            'emailMin'          => 15,
            'emailMask'         => E_ERROR | E_PARSE | E_COMPILE_ERROR | E_WARNING | E_USER_ERROR | E_USER_NOTICE,
            'emailTraceMask'    => E_WARNING | E_USER_ERROR | E_USER_NOTICE,
            'fatalMask'         => array_reduce(
                $this->errTypesGrouped['fatal'],
                create_function('$a, $b', 'return $a | $b;')
            ),
            'emailThrottleFile' => dirname(__FILE__).'/error_emails.txt',
        );
        $this->data = array(
            'errorCaller'   => array(),
            'errors'        => array(),
            'lastError'     => array(),
        );
        $this->set($cfg);
        $this->register();
        ini_set('display_errors', 0);
        error_reporting(-1);    // report every possible error ( E_ALL | E_STRICT )
                                // not actually necessary as all errors get sent to custom error handler
        register_shutdown_function(array($this,'shutdownFunction'));
        return;
    }

    /**
     * Email this error... if...
     *   Uses emailThrottleFile to keep track of when emails were sent
     *
     * @param integer $errType the level of the error
     * @param string  $errMsg  the error message
     * @param string  $file    filepath the error was raised in
     * @param string  $line    the line the error was raised in
     * @param array   $vars    active symbol table at point error occured
     *
     * @return void
     */
    protected function emailErr($errType, $errMsg, $file, $line, $vars = array())
    {
        $cfg    = &$this->cfg;
        $data   = &$this->data;
        $email = false;
        if ($cfg['emailMin'] > 0 && $cfg['emailThrottleFile']) {
            $ts_now     = time();
            $ts_cutoff  = $ts_now - $cfg['emailMin'] * 60;
            $data_str   = is_readable($cfg['emailThrottleFile'])
                            ? file_get_contents($cfg['emailThrottleFile'])
                            : '';
            $data       = unserialize($data_str);
            if (!is_array($data)) {
                $data = array(
                    'ts_trash_collection'   => $ts_now,
                    'errors'                => array(),
                );
            }
            // for key creation:
            //   use true error location
            //   remove "numbers" from error message
            $errMsgTmp = $errMsg;
            $errMsgTmp = preg_replace('/(\(.*?)\d+(.*?\))/', '\1x\2', $errMsgTmp);
            $errMsgTmp = preg_replace('/\b([a-z]+\d+)+\b/', 'xxx', $errMsgTmp);
            $errMsgTmp = preg_replace('/\b[\d.-]{4,}\b/', 'xxx', $errMsgTmp);
            $key = md5($file.$line.$errType.$errMsgTmp);
            if ($data['errorCaller']) {
                $file = $data['errorCaller']['file'];
                $line = $data['errorCaller']['file'];
            }
            if (!isset($data['errors'][$key]) || $data['errors'][$key]['tsEmailed'] < $ts_cutoff) {
                // this error has not occurred recently -> email it
                $email = true;
                if ($this->debug->get('collect') && in_array($this->debug->get('emailLog'), array('always','onError'))) {
                    // Don't email error.  debug's shutdownFunction will email
                    $email = false;
                }
                $data['errors'][$key] = array(
                    'file'          => $file,
                    'line'          => $line,
                    'errType'       => $errType,
                    'errMsg'        => $errMsg,
                    'tsEmailed'     => $ts_now,
                    'emailTo'       => $this->debug->get('emailTo'),
                    'countSince'    => 0,
                );
            } else {
                // Don't email error.  Was recently emailed.
                $data['errors'][$key]['countSince']++;
            }
            $data = $this->emailTrashCollection($data);
            $wrote = $this->fileWrite($cfg['emailThrottleFile'], serialize($data));
        }
        if ($email) {
            // send error email!
            $errMsg     = preg_replace('/ \[<a.*?\/a>\]/i', '', $errMsg);   // remove links from errMsg
            $cs         = $data['errors'][$key]['countSince'];
            $subject    = 'Website Error: '.$_SERVER['SERVER_NAME'].': '.$errMsg.( $cs ? ' ('.$cs.'x)' : '' );
            $email_body = '';
            if (!empty($cs)) {
                $email_body .= 'Error has occurred '.$cs.' times since last email.'."\n\n";
            }
            $email_body .= ''
                .'datetime: '.date('Y-m-d H:i:s (T)')."\n"
                .'errormsg: '.$errMsg."\n"
                .'errortype: '.$errType.' ('.$this->errTypes[$errType].')'."\n"
                .'file: '.$file."\n"
                .'line: '.$line."\n"
                .'remote_addr: '.$_SERVER['REMOTE_ADDR']."\n"
                .'http_host: '.$_SERVER['HTTP_HOST']."\n"
                .'request_uri: '.$_SERVER['REQUEST_URI']."\n"
                .'';
            if (!empty($_POST)) {
                $email_body .= 'post params: '.var_export($_POST, true)."\n";
            }
            if ($errType & $cfg['emailTraceMask']) {
                /*
                    backtrace:
                    0: here
                    1: call_user_func_array
                    2: errorHandler
                    3: where error occured
                */
                $search = array(
                    ")\n\n",
                );
                $replace = array(
                    ")\n",
                );
                $backtrace = debug_backtrace();
                $backtrace = array_slice($backtrace, 3);
                $backtrace[0]['vars'] = $vars;
                $str = print_r($backtrace, true);
                $str = preg_replace('/Array\s+\(\s+\)/s', 'Array()', $str); // single-lineify empty array
                $str = str_replace($search, $replace, $str);
                $str = substr($str, 0, -1);
                $email_body .= "\n".'backtrace: '.$str;
            }
            mail($this->debug->get('emailTo'), $subject, $email_body);
        }
        return;
    }

    /**
     * Clean out errors stored in emailThrottleFile that havent occured recently
     *
     * @param array $data Data structure as stored in emailThrottleFile
     *
     * @return array
     */
    protected function emailTrashCollection($data)
    {
        $ts_now     = time();
        $ts_cutoff  = $ts_now - $this->cfg['emailMin'] * 60;
        if ($data['ts_trash_collection'] < $ts_cutoff) {
            // trash collection time
            $data['ts_trash_collection'] = $ts_now;
            $email_body = '';
            foreach ($data['errors'] as $k => $err) {
                if ($err['tsEmailed'] > $ts_cutoff) {
                    continue;
                }
                // it's been a while since this error was emailed
                if ($err['emailTo'] != $this->cfg['emailTo']) {
                    if ($err['countSince'] < 1 || $err['tsEmailed'] < $ts_now - 60*60*24) {
                        unset($data['errors'][$k]);
                    }
                    continue;
                }
                unset($data['errors'][$k]);
                if ($err['countSince'] > 0) {
                    $dateLastEmailed = date('Y-m-d H:i:s', $err['tsEmailed']);
                    $email_body .= ''
                        .'File: '.$err['file']."\n"
                        .'Line: '.$err['line']."\n"
                        .'Error: '.$this->errTypes[ $err['errType'] ].': '.$err['errMsg']."\n"
                        .'Has occured '.$err['countSince'].' times since '.$dateLastEmailed."\n\n";
                }
            }
            if ($email_body) {
                mail($this->cfg['emailTo'], 'Website Errors: '.$_SERVER['SERVER_NAME'], $email_body);
            }
        }
        return $data;
    }

    /**
     * Write string to file / creates file if doesn't exist
     *
     * @param string $file filepath
     * @param string $str  string to write
     *
     * @return integer|boolean number of bytes written or false on error
     */
    public function fileWrite($file, $str)
    {
        $return = false;
        if (!file_exists($file)) {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);    // 3rd param is php 5
            }
        }
        $fh = fopen($file, 'w');
        if ($fh) {
            $return = fwrite($fh, $str);
            fclose($fh);
        }
        return $return;
    }

    /**
     * Retrieve a config or data value
     *
     * @param string $k what to get
     *
     * @return mixed
     */
    public function get($k)
    {
        $ret = null;
        if (isset($this->cfg[$k])) {
            $ret = $this->cfg[$k];
        } elseif (isset($this->data[$k])) {
            $ret = $this->data[$k];
        } elseif (isset($this->{$k})) {
            $ret = $this->{$k};
        }
        return $ret;
    }

    /**
     * Error handler
     *
     * @param integer $errType the level of the error
     * @param string  $errMsg  the error message
     * @param string  $file    filepath the error was raised in
     * @param string  $line    the line the error was raised in
     * @param array   $vars    active symbol table at point error occured
     *
     * @return void
     * @link http://www.php.net/manual/en/language.operators.errorcontrol.php
     */
    public function handler($errType, $errMsg, $file, $line, $vars = array())
    {
        $cfg = &$this->cfg;
        $data = &$this->data;
        $isFatal = $errType & $cfg['fatalMask'];
        $isSuppressed = !$isFatal && error_reporting() === 0;
        $errMd5 = md5($file.$line.$errMsg); // use true source for tracking
        $first_occur = !isset($data['errors'][$errMd5]);
        if (!empty($data['errorCaller'])) {
            $file = $data['errorCaller']['file'];
            $line = $data['errorCaller']['line'];
        }
        $err_string = $this->errTypes[$errType].': '.$file.' : '.$errMsg.' on line '.$line;
        $error = array(
            'type'      => $errType,
            'typeStr'   => $this->errTypes[$errType],
            // if any instance of this error was not supprseed, reflect that
            'suppressed'=> !$first_occur && !$data['errors'][$errMd5]['suppressed']
                ? false
                : $isSuppressed,
            // likewise if any any instance was logged in console
            'inConsole' => !$first_occur && $data['errors'][$errMd5]['inConsole']
                ? true
                : !$isSuppressed && $this->debug->get('collect'),
            'message'   => $errMsg,
            'file'      => $file,
            'line'      => $line,
        );
        $data['lastError'] = $error;
        $data['errors'][$errMd5] = $error;
        if ($isSuppressed) {
            // @suppressed error
        } elseif ($this->debug->get('collect')) {
            /*
                log error in 'console'
                  will not get logged to server's error_log
                  will not get emailed
            */
            $errors = array(E_ERROR,E_WARNING,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR);
            if (in_array($errType, $errors)) {
                $this->debug->error($err_string);
            } else {
                $this->debug->warn($err_string);
            }
            if (!$this->debug->get('output')) {
                // not currently outputing... send to error log
                error_log('PHP '.$err_string);
            }
        } elseif ($first_occur) {
            if ($this->debug->get('emailTo') && ( $errType & $cfg['emailMask'] )) {
                $args = func_get_args();
                call_user_func_array(array($this,'emailErr'), $args);
            }
            error_log('PHP '.$err_string);
        }
        if (!$isSuppressed) {
            $data['errorCaller'] = array();
        }
        if ($cfg['onError'] && is_callable($cfg['onError'])) {
            call_user_func($cfg['onError'], $isFatal);
        }
        return;
    }

    /**
     * Register this error hander and shutdown function
     *
     * @return void
     */
    public function register()
    {
        if (!$this->registered) {
            $this->registered = true;   // used by shutdownFunction()
            $this->oldErrorHandler = set_error_handler(array($this, 'handler'));
        }
    }

    /**
     * Set one or more config values
     *
     * If setting a single value via method a or b, old value is returned
     *
     * Setting/updating 'key' will also set 'collect' and 'output'
     *
     *    set('key', 'value')
     *    set('level1.level2', 'value')
     *    set(array('k1'=>'v1', 'k2'=>'v2'))
     *
     * @param string $k key
     * @param mixed  $v value
     *
     * @return mixed
     */
    public function set($k, $v = null)
    {
        $ret = null;
        if (is_string($k)) {
            $what = 'cfg';
            if (preg_match('#^(cfg|data)[\./](.+)$#', $k, $matches)) {
                $what = $matches[1];
                $k = $matches[2];
            }
            if ($what == 'cfg') {
                $ret = $this->cfg[$k];
                $this->cfg[$k] = $v;
            } else {
                $ret = $this->data[$k];
                $this->data[$k] = $v;
            }
        } elseif (is_array($k)) {
            $this->cfg = array_merge($this->cfg, $k);
        }
        return $ret;
    }

    /**
     * Set the calling file/line for next error
     * this override will apply until cleared, error occurs, or groupEnd()
     *
     * Example:  some wrapper function that is called often:
     *     Rather than reporting that an error occurred within the wrapper, you can use
     *     setErrorCaller() to report the error originating from the file/line that called the function
     *
     * @param array $caller optional. pass null or array() to clear
     *
     * @return void
     */
    public function setErrorCaller($caller = 'notPassed')
    {
        if ($caller === 'notPassed') {
            $backtrace = debug_backtrace();
            $i = isset($backtrace[1])
                ? 1
                : 0;
            $caller = isset($backtrace[$i]['file'])
                ? $backtrace[$i]
                : $backtrace[$i+1];
            $caller = array(
                'depth' => $this->debug->get('data/groupDepth'),
                'file' => $caller['file'],
                'line' => $caller['line'],
            );
        } elseif (empty($caller)) {
            $caller = array();  // clear
        } elseif (is_array($caller)) {
            $caller['depth'] = $this->debug->get('data/groupDepth');
        }
        $this->data['errorCaller'] = $caller;
        return;
    }

    /**
     * Catch Fatal Error ( if PHP >= 5.2 )
     *
     * @return void
     * @requires PHP 5.2.0
     */
    public function shutdownFunction()
    {
        if ($this->registered && version_compare(PHP_VERSION, '5.2.0', '>=')) {
            $error = error_get_last();
            if ($error['type'] & $this->cfg['fatalMask']) {
                $this->handler($error['type'], $error['message'], $error['file'], $error['line']);
                echo $this->debug->output();
            }
        }
        return;
    }

    /**
     * un-register this error hander and shutdown function
     *
     * Note:  PHP conspicuously lacks an unregister_shutdown_function function
     *     so it will continue to be called... $this->registered will be used to keep track
     *     of whether it's "registered" or not
     *
     * @return void
     */
    public function unregister()
    {
        if ($this->registered) {
            // we think we're the current error handler.. dbl check when restoring
            $errHandlerWas = set_error_handler($this->oldErrorHandler);
            if ($errHandlerWas != array($this, 'handler')) {
                // we weren't... restore to prev
                restore_error_handler();
            }
            $this->oldErrorHandler = null;
            $this->registered = false;  // used by shutdownFunction()
        }
    }
}
