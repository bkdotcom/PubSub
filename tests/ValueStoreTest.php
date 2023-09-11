<?php

namespace bdk\PubSubTests;

use bdk\PubSub\ValueStore;
use PHPUnit\Framework\TestCase;

/**
 * Test ValueStore
 *
 * @covers \bdk\PubSub\ValueStore
 */
class ValueStoreTest extends TestCase
{
    /**
     * This method is called before a test is executed.
     *
     * @return void
     */
    public function setUp(): void
    {
    }

    /**
     * This method is called after a test is executed.
     *
     * @return void
     */
    public function tearDown(): void
    {
    }

    public function testOffsetGetGetter()
    {
        $fixture = new \bdk\PubSubTests\Fixture\ValueStore();
        self::assertSame('bar', $fixture['foo']);
    }

    public function testOffsetSetAppend()
    {
        $valueStore = new Fixture\ValueStore(array(
            'ding' => 'dong',
        ));
        $valueStore[] = 'foo';
        $valueStore['scale'] = 'banana';
        $valueStore[] = 'bar';
        $this->assertSame(array(
            'ding' => 'dong',
            0 => 'foo',
            'scale' => 'banana',
            1 => 'bar',
        ), $valueStore->getValues());
        $this->assertSame(array(
            array('ding' => 'dong'),
            array(0 => 'foo'),
            array('scale' => 'banana'),
            array(1 => 'bar'),
        ), $valueStore->onSetArgs);
    }

    public function testSerialize()
    {
        $valueStore = new ValueStore(array(
            'foo' => 'bar',
        ));
        $serialized = \serialize($valueStore);
        $valueStoreNew = \unserialize($serialized);
        self::assertEquals($valueStore, $valueStoreNew);
    }

    public function testDebugInfo()
    {
        $valueStore = new ValueStore(array(
            'foo' => 'bar',
        ));
        self::assertSame(array(
            'foo' => 'bar'
        ), $valueStore->__debugInfo());
    }

    public function testJsonSerialize()
    {
        $valueStore = new ValueStore(array(
            'foo' => 'bar',
        ));
        self::assertSame('{"foo":"bar"}', \json_encode($valueStore));
    }

    public function testSerialize2()
    {
        $valueStore = new ValueStore(array(
            'foo' => 'bar',
        ));
        self::assertSame('a:1:{s:3:"foo";s:3:"bar";}', $valueStore->serialize());
    }

    public function testUnserialize2()
    {
        $valueStore = new ValueStore();
        $valueStore->unserialize('a:1:{s:3:"foo";s:3:"bar";}');
        self::assertSame(array(
            'foo' => 'bar',
        ), $valueStore->getValues());
    }
}
