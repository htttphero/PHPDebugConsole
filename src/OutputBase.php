<?php
/**
 * This file is part of PHPDebugConsole
 *
 * @package   PHPDebugConsole
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2017 Brad Kent
 * @version   v1.4.0
 */

namespace bdk\Debug;

/**
 * Base output plugin
 */
class OutputBase implements PluginInterface
{

    protected $debug;
    protected $dumpType;
    protected $dumpTypeMore;

    /**
     * Constructor
     *
     * @param object $debug debug instance
     */
    public function __construct($debug)
    {
        $this->debug = $debug;
    }

    /**
     * {@inheritdoc}
     */
    public function debugSubscribers(\bdk\Debug $debug)
    {
        return array(
            'debug.output' => 'onOutput',
        );
    }

    /**
     * Dump value

     * @param mixed $val  value to dump
     * @param array $path {@internal}
     *
     * @return string
     */
    public function dump($val, $path = array())
    {
        $typeMore = null;
        $type = $this->debug->abstracter->getType($val, $typeMore);
        if ($typeMore == 'raw') {
            $val = $this->debug->abstracter->getAbstraction($val);
            $typeMore = null;
        } elseif ($typeMore == 'abstraction') {
            $typeMore = null;
        }
        $method = 'dump'.ucfirst($type);
        $return = in_array($type, array('array', 'object'))
            ? $this->{$method}($val, $path)
            : $this->{$method}($val);
        $this->dumpType = $type;
        $this->dumpTypeMore = $typeMore;
        return $return;
    }

    /**
     * Is value a timestamp?
     *
     * @param mixed $val value to check
     *
     * @return string|false
     */
    protected function checkTimestamp($val)
    {
        $secs = 86400 * 90; // 90 days worth o seconds
        $tsNow = time();
        if ($val > $tsNow - $secs && $val < $tsNow + $secs) {
            return date('Y-m-d H:i:s', $val);
        }
        return false;
    }

    /**
     * Dump array
     *
     * @param array $array array to dump
     * @param array $path  {@internal}
     *
     * @return array
     */
    protected function dumpArray($array, $path = array())
    {
        $pathCount = count($path);
        foreach ($array as $key => $val) {
            $path[$pathCount] = $key;
            $array[$key] = $this->dump($val, $path);
        }
        return $array;
    }

    /**
     * Dump boolean
     *
     * @param boolean $val boolean value
     *
     * @return boolean
     */
    protected function dumpBool($val)
    {
        return (bool) $val;
    }

    /**
     * Dump callable
     *
     * @param array $abs array/callable abstraction
     *
     * @return string
     */
    protected function dumpCallable($abs)
    {
        return 'callable: '.$abs['values'][0].'::'.$abs['values'][1];
    }

    /**
     * Dump float value
     *
     * @param float $val float value
     *
     * @return float|string
     */
    protected function dumpFloat($val)
    {
        $date = $this->checkTimestamp($val);
        return $date
            ? $val.' ('.$date.')'
            : $val;
    }

    /**
     * Dump integer value
     *
     * @param integer $val integer value
     *
     * @return integer|string
     */
    protected function dumpInt($val)
    {
        return $this->dumpFloat($val);
    }

    /**
     * Dump null value
     *
     * @return null
     */
    protected function dumpNull()
    {
        return null;
    }

    /**
     * Dump object
     *
     * @param array $abs  object abstraction
     * @param array $path {@internal}
     *
     * @return mixed
     */
    protected function dumpObject($abs, $path = array())
    {
        if ($abs['isRecursion']) {
            $return = '(object) '.$abs['className'].' *RECURSION*';
        } elseif ($abs['isExcluded']) {
            $return = '(object) '.$abs['className'].' (not inspected)';
        } else {
            $return = array(
                '___class_name' => $abs['className'],
            );
            $pathCount = count($path);
            foreach ($abs['properties'] as $name => $info) {
                $path[$pathCount] = $name;
                $vis = $info['visibility'];
                if ($vis == 'private' && $info['inheritedFrom']) {
                    $vis = '🔒 '.$vis;
                }
                $name = '('.$vis.') '.$name;
                $return[$name] = $this->dump($info['value'], $path);
            }
        }
        return $return;
    }

    /**
     * Dump recursion (array recursion)
     *
     * @return string
     */
    protected function dumpRecursion()
    {
        return 'Array *RECURSION*';
    }

    /**
     * Dump resource
     *
     * @param array $abs resource abstraction
     *
     * @return string
     */
    protected function dumpResource($abs)
    {
        return $abs['value'];
    }

    /**
     * Dump string
     *
     * @param string $val string value
     *
     * @return string
     */
    protected function dumpString($val)
    {
        if (is_numeric($val)) {
            $date = $this->checkTimestamp($val);
            return $date
                ? $val.' ('.$date.')'
                : $val;
        } else {
            return $this->debug->utf8->dump($val);
        }
    }

    /**
     * Dump undefined
     *
     * @return null
     */
    protected function dumpUndefined()
    {
        return null;
    }

    /**
     * Build table rows
     *
     * This builds table rows usable by ChromeLogger and <script>
     *
     * @param array $array array to debug
     *
     * @return array
     */
    protected function methodTable($array)
    {
        $keys = $this->debug->utilities->arrayColKeys($array);
        $table = array();
        $classnames = array();
        foreach ($array as $k => $row) {
            $values = $this->debug->abstracter->keyValues($row, $keys, $objInfo);
            $values = array_map(function ($val) {
                if ($val === $this->debug->abstracter->UNDEFINED) {
                    return get_class($this) == __NAMESPACE__.'\\OutputScript'
                        ? $val
                        : null;
                } elseif (is_array($val)) {
                    return $this->debug->output->outputText->dump($val, false);
                } else {
                    return $val;
                }
            }, $values);
            $values = array_combine($keys, $values);
            $classnames[$k] = $objInfo
                ? $objInfo['className']
                : '';
            $table[$k] = $values;
        }
        if (array_filter($classnames)) {
            foreach ($classnames as $k => $classname) {
                $table[$k] = array_merge(
                    array('___class_name' => $classname),
                    $table[$k]
                );
            }
        }
        return $table;
    }
}
