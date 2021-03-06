<?php
/**
 * This file is part of PHPDebugConsole
 *
 * @package   PHPDebugConsole
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2018 Brad Kent
 * @version   v2.3
 */

namespace bdk\Debug;

use bdk\Debug;
use bdk\Debug\Output\OutputInterface;
use bdk\PubSub\Event;
use bdk\PubSub\SubscriberInterface;

/**
 * General Output methods
 */
class Output implements SubscriberInterface
{

    private $cfg = array();
    private $debug;

    /**
     * Constructor
     *
     * @param \bdk\Debug $debug debug instance
     * @param array      $cfg   configuration
     */
    public function __construct(Debug $debug, $cfg = array())
    {
        $this->debug = $debug;
        $this->cfg = array(
            'addBR' => false,
            'css' => '',                    // additional "override" css
            'displayListKeys' => true,
            'filepathCss' => __DIR__.'/css/Debug.css',
            'filepathScript' => __DIR__.'/js/Debug.jquery.min.js',
            'onOutput'  => null,            // set to something callable
            'outputAs'  => null,            // 'chromeLogger', 'html', 'script', 'text', or Object, if null, will be determined automatically
            'outputAsDefaultNonHtml' => 'chromeLogger',
            'outputConstants' => true,
            'outputCss' => true,            // applies when outputAs = 'html'
            'outputHeaders' => true,        // ie, ChromeLogger and/or firePHP headers
            'outputMethodDescription' => true, // (or just summary)
            'outputMethods' => true,
            'outputScript' => true,         // applies when outputAs = 'html'
        );
        $this->setCfg($cfg);
    }

    /**
     * Magic getter
     *
     * @param string $prop property to get
     *
     * @return mixed
     */
    public function __get($prop)
    {
        if (\strpos($prop, 'output') === 0) {
            $this->debug->errorHandler->setErrorCaller();
            \trigger_error('output->'.$prop.' is deprecated, use output->'.\lcfirst(\substr($prop, 6)).' instead', E_USER_DEPRECATED);
            $prop = \lcfirst(\substr($prop, 6));
            if ($this->{$prop}) {
                return $this->{$prop};
            }
        }
        $classname = __NAMESPACE__.'\\Output\\'.\ucfirst($prop);
        if (\class_exists($classname)) {
            $this->{$prop} = new $classname($this->debug);
            // note: we don't add as plugin / OutputInterface here
            return $this->{$prop};
        }
    }

    /**
     * Get config val
     *
     * @param string $path what to get
     *
     * @return mixed
     */
    public function getCfg($path = null)
    {
        if ($path == 'outputAs') {
            $ret = $this->cfg['outputAs'];
            if (!$ret) {
                $ret = $this->getDefaultOutputAs();
            }
        } elseif ($path == 'css') {
            $ret = $this->getCss();
        } else {
            $ret = $this->debug->utilities->arrayPathGet($this->cfg, $path);
        }
        return $ret;
    }

    /**
     * Return the log's CSS
     *
     * @return string
     */
    public function getCss()
    {
        $return = \file_get_contents($this->cfg['filepathCss']);
        $return .= "\n";
        if (!empty($this->cfg['css'])) {
            $return .= $this->cfg['css']."\n";
        }
        return $return;
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscriptions()
    {
        return array(
            'debug.output' => array('onOutput', 1),
        );
    }

    /**
     * debug.output subscriber
     *
     * @param Event $event debug.output event object
     *
     * @return void
     */
    public function onOutput(Event $event)
    {
        $this->data = $this->debug->getData();
        $this->closeOpenGroups();
        foreach ($this->data['logSummary'] as &$log) {
            $this->removeHideIfEmptyGroups($log);
            $this->uncollapseErrors($log);
        }
        $this->removeHideIfEmptyGroups($this->data['log']);
        $this->uncollapseErrors($this->data['log']);
        $this->debug->setData($this->data);
    }

    /**
     * Set one or more config values
     *
     * If setting a single value, old value is returned
     *
     * @param string $mixed  key=>value array or key
     * @param mixed  $newVal value
     *
     * @return mixed returns previous value
     */
    public function setCfg($mixed, $newVal = null)
    {
        $ret = null;
        $values = array();
        if (\is_string($mixed)) {
            $key = $mixed;
            $ret = isset($this->cfg[$key])
                ? $this->cfg[$key]
                : null;
            $values = array(
                $key => $newVal,
            );
        } elseif (\is_array($mixed)) {
            $values = $mixed;
        }
        if (isset($values['outputAs'])) {
            $this->setOutputAs($values['outputAs']);
            unset($values['outputAs']); // setOutputAs does the setting
        }
        if (isset($values['onOutput'])) {
            /*
                Replace - not append - subscriber set via setCfg
            */
            if (isset($this->cfg['onOutput'])) {
                $this->debug->eventManager->unsubscribe('debug.output', $this->cfg['onOutput']);
            }
            $this->debug->eventManager->subscribe('debug.output', $values['onOutput']);
        }
        $this->cfg = $this->debug->utilities->arrayMergeDeep($this->cfg, $values);
        return $ret;
    }

    /**
     * Close any unclosed groups
     *
     * We may have forgotten to end a group or the script may have exited
     *
     * @return void
     */
    private function closeOpenGroups()
    {
        $this->data['groupPriorityStack'][] = 'main';
        while ($this->data['groupPriorityStack']) {
            $priority = \array_pop($this->data['groupPriorityStack']);
            foreach ($this->data['groupStacks'][$priority] as $i => $info) {
                if ($info['collect']) {
                    unset($this->data['groupStacks'][$priority][$i]);
                    $meta = array(
                        'channel' => $info['channel'],
                    );
                    if ($priority === 'main') {
                        $this->data['log'][] = array('groupEnd', array(), $meta);
                    } else {
                        $this->data['logSummary'][$priority][] = array('groupEnd', array(), $meta);
                    }
                }
            }
        }
    }

    /**
     * Determine default outputAs
     *
     * @return string
     */
    private function getDefaultOutputAs()
    {
        $ret = 'html';
        $interface = $this->debug->utilities->getInterface();
        if ($interface == 'ajax') {
            $ret = $this->cfg['outputAsDefaultNonHtml'];
        } elseif ($interface == 'http') {
            $contentType = $this->debug->utilities->getResponseHeader();
            if ($contentType && $contentType !== 'text/html') {
                $ret = $this->cfg['outputAsDefaultNonHtml'];
            }
        } else {
            $ret = 'text';
        }
        return $ret;
    }

    /**
     * Remove empty groups with 'hideIfEmpty' meta value
     *
     * @param array $log log or summary
     *
     * @return void
     */
    private function removeHideIfEmptyGroups(&$log)
    {
        $groupStack = array();
        $groupStackCount = 0;
        $removed = false;
        for ($i = 0, $count = \count($log); $i < $count; $i++) {
            $method = $log[$i][0];
            if (\in_array($method, array('group', 'groupCollapsed'))) {
                $entry = $log[$i];
                $groupStack[] = array(
                    'i' => $i,
                    'meta' => !empty($entry[2]) ? $entry[2] : array(),
                    'hasEntries' => false,
                );
                $groupStackCount ++;
            } elseif ($method == 'groupEnd') {
                $group = \end($groupStack);
                if (!$group['hasEntries'] && !empty($group['meta']['hideIfEmpty'])) {
                    // make it go away
                    unset($log[$group['i']]);
                    unset($log[$i]);
                    $removed = true;
                }
                \array_pop($groupStack);
                $groupStackCount--;
            } elseif ($groupStack) {
                $groupStack[$groupStackCount - 1]['hasEntries'] = true;
            }
        }
        if ($removed) {
            $log = \array_values($log);
        }
    }

    /**
     * Set outputAs value
     * instantiate object if necessary & addPlugin if not already subscribed
     *
     * @param OutputInterface|string $outputAs OutputInterface instance, or (short) classname
     *
     * @return void
     */
    private function setOutputAs($outputAs)
    {
        if (\is_object($this->cfg['outputAs'])) {
            /*
                unsubscribe current OutputInterface
                there can only be one 'outputAs' at a time
                if multiple output routes are desired, use debug->addPlugin()
            */
            $this->debug->removePlugin($this->cfg['outputAs']);
            $this->cfg['outputAs'] = null;
        }
        $prop = null;
        $obj = null;
        if (\is_string($outputAs)) {
            $prop = $outputAs;
            $classname = __NAMESPACE__.'\\Output\\'.\ucfirst($outputAs);
            if (\property_exists($this, $prop)) {
                $obj = $this->{$prop};
            } elseif (\class_exists($classname)) {
                $obj = new $classname($this->debug);
            }
        } elseif ($outputAs instanceof OutputInterface) {
            $classname = \get_class($outputAs);
            $prefix = __NAMESPACE__.'\\Output\\';
            if (\strpos($classname, $prefix) == 0) {
                $prop = \substr($classname, \strlen($prefix));
                $prop = \lcfirst($prop);
            }
            $obj = $outputAs;
        }
        if ($obj) {
            $this->debug->addPlugin($obj);
            $this->cfg['outputAs'] = $obj;
            if ($prop) {
                $this->{$prop} = $obj;
            }
        }
    }

    /**
     * Uncollapse groups containing errors.
     *
     * @param array $log log or summary
     *
     * @return void
     */
    private function uncollapseErrors(&$log)
    {
        $groupStack = array();
        for ($i = 0, $count = \count($log); $i < $count; $i++) {
            $method = $log[$i][0];
            if (\in_array($method, array('group', 'groupCollapsed'))) {
                $groupStack[] = $i;
            } elseif ($method == 'groupEnd') {
                \array_pop($groupStack);
            } elseif (\in_array($method, array('error', 'warn'))) {
                foreach ($groupStack as $i2) {
                    $log[$i2][0] = 'group';
                }
            }
        }
    }
}
