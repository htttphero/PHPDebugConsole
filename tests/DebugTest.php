<?php

/**
 * PHPUnit tests for Debug class
 */
class DebugTest extends PHPUnit_Framework_TestCase
{

    /**
     * setUp is executed before each test
     *
     * @return void
     */
    public function setUp()
    {
        $this->debug = new \bdk\Debug\Debug(array(
            'collect' => true,
            // 'output' => true,
            // 'outputCss' => false,
            // 'outputScript' => false,
            // 'outputAs' => 'html',
        ));
    }

    /**
     * tearDown is executed after each test
     *
     * @return void
     */
    public function tearDown()
    {
        $this->debug->set('output', false);
    }

    /**
     * for given $var, check if it's abstraction type is of $type
     *
     * @param array  $var  abstracted $var
     * @param string $type array, object, or resource
     *
     * @return boolean
     */
    protected function checkAbstractionType($var, $type)
    {
        $return = false;
        if ($type == 'array') {
            $return = $var['debug'] === \bdk\Debug\VarDump::ABSTRACTION
                && $var['type'] === 'array'
                && isset($var['values'])
                && isset($var['isRecursion']);
        } elseif ($type == 'object') {
            $keys = array('excluded','collectMethods','viaDebugInfo','isRecursion',
                    'extends','implements','constants','properties','methods','scopeClass','stringified');
            $keysMissing = array_diff($keys, array_keys($var));
            $return = $var['debug'] === \bdk\Debug\VarDump::ABSTRACTION
                && $var['type'] === 'object'
                && $var['className'] === 'stdClass'
                && count($keysMissing) == 0;
        } elseif ($type == 'resource') {
            $return = $var['debug'] === \bdk\Debug\VarDump::ABSTRACTION
                && $var['type'] === 'resource'
                && isset($var['value']);
        }
        return $return;
    }

    /**
     * Util to output to console / help in creation of tests
     */
    public function output($label, $var)
    {
        fwrite(STDOUT, $label.' = '.print_r($var, true) . "\n");
    }

    /**
     * Test
     *
     * @return void
     */
    public function testAssert()
    {
        $this->debug->assert(false, 'this is false');
        $this->debug->assert(true, 'this is true... not logged');
        $log = $this->debug->dataGet('log');
        $this->assertCount(1, $log);
        $this->assertSame(array('assert','this is false'), $log[0]);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testCount()
    {
        $this->debug->count('count test');
        for ($i=0; $i<3; $i++) {
            $this->debug->count();
            $this->debug->count('count test');
        }
        $log = $this->debug->dataGet('log');
        $last2 = array_slice($log, -2);
        $this->assertSame(array(
            array('count', 'count', 3),
            array('count', 'count test', 4),
        ), $last2);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testError()
    {
        $resource = fopen(__FILE__, 'r');
        $this->debug->error('a string', array(), new stdClass(), $resource);
        fclose($resource);
        $log = $this->debug->dataGet('log');
        $logEntry = $log[0];
        $this->assertSame('error', $logEntry[0]);
        $this->assertSame('a string', $logEntry[1]);
        // check array abstraction
        $isArray = $this->checkAbstractionType($logEntry[2], 'array');
        $isObject = $this->checkAbstractionType($logEntry[3], 'object');
        $isResource = $this->checkAbstractionType($logEntry[4], 'resource');
        $this->assertTrue($isArray, 'is Array');
        $this->assertTrue($isObject, 'is Object');
        $this->assertTrue($isResource, 'is Resource');
    }

    /**
     * Test
     *
     * @return void
     */
    public function testGet()
    {
    }

    /**
     * Test
     *
     * @return void
     */
    public function testGroup()
    {
        $this->debug->group('a', 'b', 'c');
        $log = $this->debug->dataGet('log');
        $this->assertSame(array('group','a','b','c'), $log[0]);
        $depth = $this->debug->dataGet('groupDepth');
        $this->assertSame(1, $depth);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testGroupCollapsed()
    {
        $this->debug->groupCollapsed('a', 'b', 'c');
        $log = $this->debug->dataGet('log');
        $this->assertSame(array('groupCollapsed', 'a','b','c'), $log[0]);
        $depth = $this->debug->dataGet('groupDepth');
        $this->assertSame(1, $depth);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testGroupUncollapse()
    {
        $this->debug->groupCollapsed('level1 (test)');
        $this->debug->groupCollapsed('level2');
        $this->debug->log('left collapsed');
        $this->debug->groupEnd('level2');
        $this->debug->groupCollapsed('level2 (test)');
        $this->debug->groupUncollapse();
        $log = $this->debug->dataGet('log');
        $this->assertSame('group', $log[0][0]);
        $this->assertSame('groupCollapsed', $log[1][0]);
        $this->assertSame('group', $log[4][0]);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testGroupEnd()
    {
        $this->debug->group('a', 'b', 'c');
        $this->debug->groupEnd();
        $depth = $this->debug->dataGet('groupDepth');
        $this->assertSame(0, $depth);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testInfo()
    {
        $resource = fopen(__FILE__, 'r');
        $this->debug->info('a string', array(), new stdClass(), $resource);
        fclose($resource);
        $log = $this->debug->dataGet('log');
        $logEntry = $log[0];
        $this->assertSame('info', $logEntry[0]);
        $this->assertSame('a string', $logEntry[1]);
        // check array abstraction
        $isArray = $this->checkAbstractionType($logEntry[2], 'array');
        $isObject = $this->checkAbstractionType($logEntry[3], 'object');
        $isResource = $this->checkAbstractionType($logEntry[4], 'resource');
        $this->assertTrue($isArray);
        $this->assertTrue($isObject);
        $this->assertTrue($isResource);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testLog()
    {
        $resource = fopen(__FILE__, 'r');
        $this->debug->log('a string', array(), new stdClass(), $resource);
        fclose($resource);
        $log = $this->debug->dataGet('log');
        $logEntry = $log[0];
        $this->assertSame('log', $logEntry[0]);
        $this->assertSame('a string', $logEntry[1]);
        // check array abstraction
        $isArray = $this->checkAbstractionType($logEntry[2], 'array');
        $isObject = $this->checkAbstractionType($logEntry[3], 'object');
        $isResource = $this->checkAbstractionType($logEntry[4], 'resource');
        $this->assertTrue($isArray);
        $this->assertTrue($isObject);
        $this->assertTrue($isResource);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testOutput()
    {
    }

    /**
     * Test
     *
     * @return void
     */
    public function testSet()
    {
    }

    /**
     * Test
     *
     * @return void
     */
    public function testSetErrorCaller()
    {
    }

    /**
     * Test
     *
     * @return void
     */
    public function testTable()
    {
        $list = array(
            // note different order of keys / not all rows have all cols
            array('name'=>'Bob', 'age'=>'12', 'sex'=>'M', 'Naughty'=>false),
            array('Naughty'=>true, 'name'=>'Sally', 'extracol' => 'yes', 'sex'=>'F', 'age'=>'10'),
        );
        $this->debug->table('list', 'blah', $list, 'blah');
        $this->debug->table($list);
        $this->debug->table($list, 'list', array('ignored arg'), 'blah');
        $this->debug->table('empty array', array());
        $this->debug->table('no array', 'here');
        $isArray1 = $this->checkAbstractionType($this->debug->dataGet('log/0/1'), 'array');
        $isArray2 = $this->checkAbstractionType($this->debug->dataGet('log/1/1'), 'array');
        $isArray3 = $this->checkAbstractionType($this->debug->dataGet('log/2/1'), 'array');
        $isArray4 = $this->checkAbstractionType($this->debug->dataGet('log/3/1'), 'array');
        $this->assertTrue($isArray1);
        $this->assertTrue($isArray2);
        $this->assertTrue($isArray3);
        $this->assertTrue($isArray4);
        $this->assertSame(array('log','no array','here'), $this->debug->dataGet('log/4'));
        // test labels
        $this->assertSame('list blah blah', $this->debug->dataGet('log/0/2'));
        $this->assertSame('list blah', $this->debug->dataGet('log/2/2'));
    }

    /**
     * Test
     *
     * @return void
     */
    public function testTime()
    {
        $this->debug->time();
        $this->debug->time('some label');
        $this->assertInternalType('float', $this->debug->dataGet('timers/stack/0'));
        $this->assertInternalType('float', $this->debug->dataGet('timers/labels/some label/1'));
    }

    /**
     * Test
     *
     * @return void
     */
    public function testTimeEnd()
    {
        $this->debug->time();
        $this->debug->time('my label');
        $this->debug->timeEnd();            // appends log
        // test stack is now empty
        $this->assertCount(0, $this->debug->dataGet('timers/stack'));
        $this->debug->timeEnd('my label');  // appends log
        $ret = $this->debug->timeEnd('my label', true);
        $this->assertInternalType('float', $ret);
        // test last timeEnd didn't append log
        $this->assertCount(2, $this->debug->dataGet('log'));
        $timers = $this->debug->dataGet('timers');
        $this->assertInternalType('float', $timers['labels']['my label'][0]);
        $this->assertNull($timers['labels']['my label'][1]);
        $this->debug->timeEnd('my label', 'blah%labelblah%timeblah');
        $this->assertStringMatchesFormat('blahmy labelblah%fblah', $this->debug->dataGet('log/2/1'));
    }

    /**
     * Test
     *
     * @return void
     */
    public function testTimeGet()
    {
        $this->debug->time();
        $this->debug->time('my label');
        $this->debug->timeGet();            // appends log
        // test stack is still 1
        $this->assertCount(1, $this->debug->dataGet('timers/stack'));
        $this->debug->timeGet('my label');  // appends log
        $ret = $this->debug->timeGet('my label', true);
        $this->assertInternalType('float', $ret);
        // test last timeEnd didn't append log
        $this->assertCount(2, $this->debug->dataGet('log'));
        $timers = $this->debug->dataGet('timers');
        $this->assertSame(0, $timers['labels']['my label'][0]);
        // test not paused
        $this->assertNotNull($timers['labels']['my label'][1]);
        $this->debug->timeGet('my label', 'blah%labelblah%timeblah');
        $this->assertStringMatchesFormat('blahmy labelblah%fblah', $this->debug->dataGet('log/2/1'));
    }

    /**
     * Test
     *
     * @return void
     */
    public function testWarn()
    {
        $resource = fopen(__FILE__, 'r');
        $this->debug->warn('a string', array(), new stdClass(), $resource);
        fclose($resource);
        $log = $this->debug->dataGet('log');
        $logEntry = $log[0];
        $this->assertSame('warn', $logEntry[0]);
        $this->assertSame('a string', $logEntry[1]);
        // check array abstraction
        $isArray = $this->checkAbstractionType($logEntry[2], 'array');
        $isObject = $this->checkAbstractionType($logEntry[3], 'object');
        $isResource = $this->checkAbstractionType($logEntry[4], 'resource');
        $this->assertTrue($isArray);
        $this->assertTrue($isObject);
        $this->assertTrue($isResource);
    }
}
