<?php
/**
 * This file is part of PHPDebugConsole
 *
 * @package   PHPDebugConsole
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2018 Brad Kent
 * @version   v2.1.0
 */

namespace bdk\Debug\Output;

use bdk\Debug\Table;
use bdk\PubSub\Event;

/**
 * Output log via FirePHP
 */
class Firephp extends Base
{

    /**
     * @var array each method will generate 1 or more headers.
     *             store those headers here for unit tests
     */
    public $lastHeadersSent = array();
    public $unitTestMode = false;

    protected $firephpMethods = array(
        'log' => 'LOG',
        'info' => 'INFO',
        'warn' => 'WARN',
        'error' => 'ERROR',
        'table' => 'TABLE',
        'group' => 'GROUP_START',
        'groupCollapsed' => 'GROUP_START',
        'groupEnd' => 'GROUP_END',
    );
    protected $messageIndex = 0;

    const FIREPHP_PROTO_VER = '0.3';
    const MESSAGE_LIMIT = 99999;

    /**
     * Output the log via FirePHP headers
     *
     * @param Event $event debug.output event object
     *
     * @return void
     */
    public function onOutput(Event $event)
    {
        if (\headers_sent($file, $line)) {
            \trigger_error('Unable to FirePHP: headers already sent. ('.$file.' line '.$line.')', E_USER_NOTICE);
            return;
        }
        $this->data = $this->debug->getData();
        $this->setHeader('X-Wf-Protocol-1', 'http://meta.wildfirehq.org/Protocol/JsonStream/0.2');
        $this->setHeader('X-Wf-1-Plugin-1', 'http://meta.firephp.org/Wildfire/Plugin/FirePHP/Library-FirePHPCore/'.self::FIREPHP_PROTO_VER);
        $this->setHeader('X-Wf-1-Structure-1', 'http://meta.firephp.org/Wildfire/Structure/FirePHP/FirebugConsole/0.1');
        $this->processLogEntryWEvent('groupCollapsed', array('PHP: '.$_SERVER['REQUEST_METHOD'].' '.$_SERVER['REQUEST_URI']));
        $this->processAlerts();
        $this->processSummary();
        $this->processLog();
        $this->processLogEntryWEvent('groupEnd');
        $this->setHeader('X-Wf-1-Index', $this->messageIndex);
        $this->data = array();
        return;
    }

    /**
     * output a log entry to Firephp
     *
     * @param string $method method
     * @param array  $args   args
     * @param array  $meta   meta values
     *
     * @return void
     */
    public function processLogEntry($method, $args = array(), $meta = array())
    {
        $value = null;
        $firePhpMeta = $this->getMeta($method, $meta);
        if (\in_array($method, array('group','groupCollapsed'))) {
            $firePhpMeta['Label'] = $args[0];
        } elseif ($method == 'table') {
            $value = $this->methodTable($args[0], $meta['columns']);
            if ($meta['caption']) {
                $firePhpMeta['Label'] = $meta['caption'];
            }
        } elseif ($method == 'trace') {
            $firePhpMeta['Type'] = $this->firephpMethods['table'];
            $value = $this->methodTable($args[0], array('function','file','line'));
            $firePhpMeta['Label'] = 'trace';
        } elseif (\count($args)) {
            if (\count($args) == 1) {
                $value = $args[0];
                // no label;
            } else {
                $firePhpMeta['Label'] = \array_shift($args);
                $value = \count($args) > 1
                    ? $args // firephp only supports label/value...  we'll pass multiple values as an array
                    : $args[0];
            }
        }
        $value = $this->dump($value);
        if ($this->messageIndex < self::MESSAGE_LIMIT) {
            $this->setFirephpHeader($firePhpMeta, $value);
        } elseif ($this->messageIndex === self::MESSAGE_LIMIT) {
            $this->setFirephpHeader(
                array('Type'=>$this->firephpMethods['warn']),
                'Limit of '.\number_format(self::MESSAGE_LIMIT).' firePhp messages reached!'
            );
        }
        return;
    }

    /**
     * Initialize firephp's meta array
     *
     * @param string $method PHPDebugConsole method
     * @param array  $meta   PHPDebugConsole meta values
     *
     * @return array
     */
    private function getMeta($method, $meta)
    {
        $firePhpMeta = array(
            'Type' => isset($this->firephpMethods[$method])
                ? $this->firephpMethods[$method]
                : $this->firephpMethods['log'],
            // Label
            // File
            // Line
            // Collapsed  (for group)
        );
        if (isset($meta['file'])) {
            $firePhpMeta['File'] = $meta['file'];
            $firePhpMeta['Line'] = $meta['line'];
        }
        if (\in_array($method, array('group','groupCollapsed'))) {
            $firePhpMeta['Collapsed'] = $method == 'groupCollapsed'
                // yes, strings
                ? 'true'
                : 'false';
        }
        return $firePhpMeta;
    }

    /**
     * Build table rows
     *
     * @param array $array   array to debug
     * @param array $columns columns to display
     *
     * @return array
     */
    protected function methodTable($array, $columns = array())
    {
        $table = array();
        $keys = $columns ?: $this->debug->table->colKeys($array);
        $headerVals = $keys;
        foreach ($headerVals as $i => $val) {
            if ($val === Table::SCALAR) {
                $headerVals[$i] = 'value';
            }
        }
        \array_unshift($headerVals, '');
        $table[] = $headerVals;
        $classNames = array();
        if ($this->debug->abstracter->isAbstraction($array) && $array['traverseValues']) {
            $array = $array['traverseValues'];
        }
        foreach ($array as $k => $row) {
            $values = $this->debug->table->keyValues($row, $keys, $objInfo);
            foreach ($values as $k2 => $val) {
                if ($val === $this->debug->abstracter->UNDEFINED) {
                    $values[$k2] = null;
                }
            }
            $classNames[] = $objInfo['row']
                ? $objInfo['row']['className']
                : '';
            \array_unshift($values, $k);
            $table[] = \array_values($values);
        }
        if (\array_filter($classNames)) {
            \array_unshift($table[0], '');
            // first col is row key.
            // insert classname after key
            foreach ($classNames as $i => $className) {
                \array_splice($table[$i+1], 1, 0, $className);
            }
        }
        return $table;
    }

    /**
     * "output" FirePHP header(s) for log entry
     *
     * @param array $meta  meta information
     * @param mixed $value value
     *
     * @return void
     */
    private function setFirephpHeader($meta, $value = null)
    {
        $this->lastHeadersSent = array();
        $msg = \json_encode(array(
            $meta,
            $value,
        ));
        $structureIndex = 1;    // refers to X-Wf-1-Structure-1
        $parts = \explode("\n", \rtrim(\chunk_split($msg, 5000, "\n")));
        $numParts = \count($parts);
        for ($i=0; $i<$numParts; $i++) {
            $part = $parts[$i];
            $this->messageIndex++;
            $headerName = 'X-Wf-1-'.$structureIndex.'-1-'.$this->messageIndex;
            $headerValue = ( $i==0 ? \strlen($msg) : '')
                . '|' . $part . '|'
                . ( $i<$numParts-1 ? '\\' : '' );
            $this->lastHeadersSent[] = $headerName.': '.$headerValue;
            $this->setHeader($headerName, $headerValue);
        }
    }

    /**
     * Tis but a simple wrapper for php's header() func
     *
     * @param string $name  header name
     * @param string $value header value
     *
     * @return void
     */
    private function setHeader($name, $value)
    {
        if (!$this->unitTestMode) {
            \header($name.': '.$value);
        }
    }
}
