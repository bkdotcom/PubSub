<?php

namespace bdk\PubSubTests;

use bdk\PubSub\Event;
use PHPUnit\Framework\TestCase;

/**
 * PHPUnit tests for Debug class
 *
 * @coversDefaultClass \bdk\PubSub\Event
 * @uses               \bdk\PubSub\Event
 */
class EventTest extends TestCase
{
    /**
     * @var \bdk\PubSub\Event
     */
    protected $event;

    /**
     * This method is called before a test is executed.
     *
     * @return void
     */
    public function setUp(): void
    {
        $this->event = new Event($this, array('foo' => 'bar'));
        $this->event->setValue('ding', 'dong');
        $this->event['mellow'] = 'yellow';
    }

    /**
     * This method is called after a test is executed.
     *
     * @return void
     */
    public function tearDown(): void
    {
        $this->event = null;
    }

    /**
     * @covers ::__construct
     */
    public function testConstruct()
    {
        $this->assertSame($this, $this->event->getSubject());
        $this->assertSame(array(
            'foo' => 'bar',
            'ding' => 'dong',
            'mellow' => 'yellow',
        ), $this->event->getValues());
    }

    /**
     * @covers ::__debugInfo
     */
    public function testDebugInfo()
    {
        if (PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('__debugInfo introduced in PHP 5.6');
        }
        $haveXdebug = \extension_loaded('xdebug');
        if ($haveXdebug) {
            $xdebugVer = \phpversion('xdebug');
            if (\version_compare($xdebugVer, '3.0.0', '<')) {
                $this->markTestSkipped('xDebug < 3.0.0 ignores __debugInfo');
            }
        }

        \ob_start();
        \var_dump($this->event);
        $varDump = \ob_get_clean();
        $varDump = \preg_replace('/^[^:]+:\d+:\n/', '', $varDump);
        $varDump = \preg_replace('/\[(\S+)\]=>\n\s*/', '$1 => ', $varDump);
        $varDump = \preg_replace('/(\S)\s*=>\n\s*/', '$1 => ', $varDump);
        $varDump = \preg_replace('/(object\([\\\a-z0-9]+\))#\d+ \(\d+\) /i', '$1 ', $varDump);
        $varDump = \preg_replace('/(class [\\\a-z0-9]+)#\d+ \(\d+\) /i', '$1 ', $varDump);
        $varDump = \preg_replace('/ => (array|string)\(\d+\) /', ' => ', $varDump);
        $varDump = \preg_replace('/ => bool\((true|false)\)/', ' => $1', $varDump);
        $varDump = \preg_replace('/(?:public|protected|private) \$(\w+)/', '$1', $varDump);
        $varDump = \trim($varDump);
        $expect = 'class bdk\PubSub\Event {
  propagationStopped => false
  subject => "bdk\PubSubTests\EventTest"
  values => {
    \'foo\' => "bar"
    \'ding\' => "dong"
    \'mellow\' => "yellow"
  }
}';
        $this->assertSame($expect, $varDump);
    }

    /**
     * @covers ::getSubject
     */
    public function testGetSubject()
    {
        $this->assertInstanceOf(__CLASS__, $this->event->getSubject());
    }

    /**
     * @covers ::getValue
     */
    public function testGetValue()
    {
        $this->assertSame('bar', $this->event->getValue('foo'));
        $this->assertSame(null, $this->event->getValue('undefined'));
    }

    /**
     * @covers ::setValues
     * @covers ::getValues
     */
    public function testGetSetValues()
    {
        $this->assertSame(array(
            'foo' => 'bar',
            'ding' => 'dong',
            'mellow' => 'yellow',
        ), $this->event->getValues());
        $this->assertSame('bar', $this->event->getValue('foo'));
        $this->assertSame('bar', $this->event['foo']);
        $this->event->setValues(array('pizza' => 'pie'));
        $this->assertSame(array(
            'pizza' => 'pie',
        ), $this->event->getValues());
        $this->assertFalse($this->event->hasValue('foo'));
    }

    /**
     * @covers ::hasValue
     */
    public function testHasValue()
    {
        $this->assertTrue($this->event->hasValue('foo'));
        $this->assertFalse($this->event->hasValue('waldo'));
    }

    /**
     * @covers ::offsetExists
     */
    public function testOffsetExists()
    {
        $this->assertSame(true, isset($this->event['foo']));
        $this->assertSame(false, isset($this->event['undefined']));
    }

    /**
     * @covers ::offsetGet
     */
    public function testOffsetGet()
    {
        $this->assertSame('bar', $this->event['foo']);
        $this->assertSame(null, $this->event['undefined']);
    }

    /**
     * @covers ::offsetSet
     * @covers ::setValue
     * @covers ::onSet
     */
    public function testOffsetSet()
    {
        $this->assertSame('yellow', $this->event->getValue('mellow'));
    }

    /**
     * @covers ::offsetUnset
     */
    public function testOffsetUnset()
    {
        unset($this->event['foo'], $this->event['undefined']);
        $this->assertSame(array(
            'ding' => 'dong',
            'mellow' => 'yellow',
        ), $this->event->getValues());
    }

    /**
     * @covers ::getIterator
     */
    public function testGetIterator()
    {
        $vals = array();
        foreach ($this->event as $k => $v) {
            $vals[$k] = $v;
        }
        $this->assertSame(array(
            'foo' => 'bar',
            'ding' => 'dong',
            'mellow' => 'yellow',
        ), $vals);
    }

    /**
     * @covers ::isPropagationStopped
     */
    public function testIsPropagationStoppedFalse()
    {
        $this->assertFalse($this->event->isPropagationStopped());
    }

    /**
     * @covers ::stopPropagation
     * @covers ::isPropagationStopped
     */
    public function testStopPropagation()
    {
        $this->event->stopPropagation();
        $this->assertTrue($this->event->isPropagationStopped());
    }
}
