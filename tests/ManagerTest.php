<?php

namespace bdk\PubSubTests;

use bdk\PubSub\Event;
use bdk\PubSub\Manager;
use bdk\PubSub\SubscriberInterface;
use PHPUnit\Framework\TestCase;

/**
 * PHPUnit tests for Debug class
 */
class ManagerTest extends TestCase
{
    /*
        Some pseudo events
    */
    const PRE_FOO = 'pre.foo';
    const POST_FOO = 'post.foo';
    const PRE_BAR = 'pre.bar';
    const POST_BAR = 'post.bar';

    /**
     * @var Manager
     */
    private $manager;

    private $subscriber;

    /**
     * This method is called before a test is executed.
     *
     * @return void
     */
    public function setUp(): void
    {
        $this->manager = $this->createManager();
        $this->subscriber = new TestSubscriber();
    }

    /**
     * This method is called after a test is executed.
     *
     * @return void
     */
    public function tearDown(): void
    {
        $this->manager = null;
        $this->subscriber = null;
    }

    public function testInitialState()
    {
        $this->assertEquals(array(), $this->manager->getSubscribers());
        $this->assertFalse($this->manager->hasSubscribers(self::PRE_FOO));
        $this->assertFalse($this->manager->hasSubscribers(self::POST_FOO));
    }

    public function testSubscribe()
    {
        $this->manager->subscribe(self::PRE_FOO, array($this->subscriber, 'preFoo'));
        $this->manager->subscribe(self::POST_FOO, array($this->subscriber, 'postFoo'));
        $this->assertTrue($this->manager->hasSubscribers());
        $this->assertTrue($this->manager->hasSubscribers(self::PRE_FOO));
        $this->assertTrue($this->manager->hasSubscribers(self::POST_FOO));
        $this->assertCount(1, $this->manager->getSubscribers(self::PRE_FOO));
        $this->assertCount(1, $this->manager->getSubscribers(self::POST_FOO));
        $this->assertCount(2, $this->manager->getSubscribers());
    }

    public function testGetListenersSortsByPriority()
    {
        $subscriber1 = new TestSubscriber();
        $subscriber2 = new TestSubscriber();
        $subscriber3 = new TestSubscriber();
        $subscriber1->name = '1';
        $subscriber2->name = '2';
        $subscriber3->name = '3';

        $this->manager->subscribe(self::PRE_FOO, array($subscriber1, 'preFoo'), -10);
        $this->manager->subscribe(self::PRE_FOO, array($subscriber2, 'preFoo'), 10);
        $this->manager->subscribe(self::PRE_FOO, array($subscriber3, 'preFoo'));

        $expected = array(
            array($subscriber2, 'preFoo'),
            array($subscriber3, 'preFoo'),
            array($subscriber1, 'preFoo'),
        );

        $this->assertSame($expected, $this->manager->getSubscribers(self::PRE_FOO));
    }

    public function testGetAllListenersSortsByPriority()
    {
        $subscriber1 = new TestSubscriber();
        $subscriber2 = new TestSubscriber();
        $subscriber3 = new TestSubscriber();
        $subscriber4 = new TestSubscriber();
        $subscriber5 = new TestSubscriber();
        $subscriber6 = new TestSubscriber();

        $this->manager->subscribe(self::PRE_FOO, $subscriber1, -10);
        $this->manager->subscribe(self::PRE_FOO, $subscriber2);
        $this->manager->subscribe(self::PRE_FOO, $subscriber3, 10);
        $this->manager->subscribe(self::POST_FOO, $subscriber4, -10);
        $this->manager->subscribe(self::POST_FOO, $subscriber5);
        $this->manager->subscribe(self::POST_FOO, $subscriber6, 10);

        $expected = array(
            self::PRE_FOO => array($subscriber3, $subscriber2, $subscriber1),
            self::POST_FOO => array($subscriber6, $subscriber5, $subscriber4),
        );

        $this->assertSame($expected, $this->manager->getSubscribers());
    }

    /*
    public function testGetListenerPriority()
    {
        $subscriber1 = new TestSubscriber();
        $subscriber2 = new TestSubscriber();

        $this->manager->subscribe('pre.foo', $subscriber1, -10);
        $this->manager->subscribe('pre.foo', $subscriber2);

        $this->assertSame(-10, $this->manager->getListenerPriority('pre.foo', $subscriber1));
        $this->assertSame(0, $this->manager->getListenerPriority('pre.foo', $subscriber2));
        $this->assertNull($this->manager->getListenerPriority('pre.bar', $subscriber2));
        $this->assertNull($this->manager->getListenerPriority('pre.foo', function () {}));
    }
    */

    public function testPublish()
    {
        $this->manager->subscribe(self::PRE_FOO, array($this->subscriber, 'preFoo'));
        $this->manager->subscribe(self::POST_FOO, array($this->subscriber, 'postFoo'));
        $this->manager->publish(self::PRE_FOO);
        $this->assertTrue($this->subscriber->preFooInvoked);
        $this->assertFalse($this->subscriber->postFooInvoked);
        $this->assertInstanceOf('bdk\\PubSub\\Event', $this->manager->publish('noevent'));
        $this->assertInstanceOf('bdk\\PubSub\\Event', $this->manager->publish(self::PRE_FOO));
        $event = new Event();
        $return = $this->manager->publish(self::PRE_FOO, $event);
        $this->assertSame($event, $return);
    }

    public function testPublishForClosure()
    {
        $invoked = 0;
        $subscriber = function () use (&$invoked) {
            ++$invoked;
        };
        $this->manager->subscribe(self::PRE_FOO, $subscriber);
        $this->manager->subscribe(self::POST_FOO, $subscriber);
        $this->manager->publish(self::PRE_FOO);
        $this->assertEquals(1, $invoked);
    }

    public function testStopEventPropagation()
    {
        $otherListener = new TestSubscriber();

        // postFoo() stops the propagation, so only one subscriber should
        // be executed
        // Manually set priority to enforce $this->subscriber to be called first
        $this->manager->subscribe(self::POST_FOO, array($this->subscriber, 'postFoo'), 10);
        $this->manager->subscribe(self::POST_FOO, array($otherListener, 'preFoo'));
        $this->manager->publish(self::POST_FOO);
        $this->assertTrue($this->subscriber->postFooInvoked);
        $this->assertFalse($otherListener->postFooInvoked);
    }

    public function testPublishByPriority()
    {
        $invoked = array();
        $subscriber1 = function () use (&$invoked) {
            $invoked[] = '1';
        };
        $subscriber2 = function () use (&$invoked) {
            $invoked[] = '2';
        };
        $subscriber3 = function () use (&$invoked) {
            $invoked[] = '3';
        };
        $this->manager->subscribe(self::PRE_FOO, $subscriber1, -10);
        $this->manager->subscribe(self::PRE_FOO, $subscriber2);
        $this->manager->subscribe(self::PRE_FOO, $subscriber3, 10);
        $this->manager->publish(self::PRE_FOO);
        $this->assertEquals(array('3', '2', '1'), $invoked);
    }

    public function testUnsubscribe()
    {
        $this->manager->subscribe(self::PRE_BAR, $this->subscriber);
        $this->assertTrue($this->manager->hasSubscribers(self::PRE_BAR));
        $this->manager->unsubscribe(self::PRE_BAR, $this->subscriber);
        $this->assertFalse($this->manager->hasSubscribers(self::PRE_BAR));
        $this->manager->unsubscribe('notExists', $this->subscriber);
    }

    public function testAddSubscriberInterface()
    {
        $eventSubscriber = new TestSubscriberInterface();
        $this->manager->addSubscriberInterface($eventSubscriber);
        $this->assertTrue($this->manager->hasSubscribers(self::PRE_FOO));
        $this->assertTrue($this->manager->hasSubscribers(self::POST_FOO));
    }

    public function testAddSubscriberInterfaceWithPriorities()
    {
        $eventSubscriber = new TestSubscriberInterface();
        $this->manager->addSubscriberInterface($eventSubscriber);

        $eventSubscriber = new TestSubscriberInterfaceWithPriorities();
        $this->manager->addSubscriberInterface($eventSubscriber);

        $subscribers = $this->manager->getSubscribers(self::PRE_FOO);
        $this->assertTrue($this->manager->hasSubscribers(self::PRE_FOO));
        $this->assertCount(2, $subscribers);
        $this->assertInstanceOf('bdk\\PubSubTests\\TestSubscriberInterfaceWithPriorities', $subscribers[0][0]);
    }

    public function testAddSubscriberInterfaceWithMultipleSubscribers()
    {
        $eventSubscriber = new TestSubscriberInterfaceWithMultipleSubscribers();
        $this->manager->addSubscriberInterface($eventSubscriber);

        $subscribers = $this->manager->getSubscribers(self::PRE_FOO);
        $this->assertTrue($this->manager->hasSubscribers(self::PRE_FOO));
        $this->assertCount(2, $subscribers);
        $this->assertEquals('preFoo2', $subscribers[0][1]);
    }

    public function testRemoveSubscriberInterface()
    {
        $eventSubscriber = new TestSubscriberInterface();
        $this->manager->addSubscriberInterface($eventSubscriber);
        $this->assertTrue($this->manager->hasSubscribers(self::PRE_FOO));
        $this->assertTrue($this->manager->hasSubscribers(self::POST_FOO));
        $this->manager->removeSubscriberInterface($eventSubscriber);
        $this->assertFalse($this->manager->hasSubscribers(self::PRE_FOO));
        $this->assertFalse($this->manager->hasSubscribers(self::POST_FOO));
    }

    public function testRemoveSubscriberInterfaceWithPriorities()
    {
        $eventSubscriber = new TestSubscriberInterfaceWithPriorities();
        $this->manager->addSubscriberInterface($eventSubscriber);
        $this->assertTrue($this->manager->hasSubscribers(self::PRE_FOO));
        $this->manager->removeSubscriberInterface($eventSubscriber);
        $this->assertFalse($this->manager->hasSubscribers(self::PRE_FOO));
    }

    public function testRemoveSubscriberInterfaceWithMultipleSubscribers()
    {
        $eventSubscriber = new TestSubscriberInterfaceWithMultipleSubscribers();
        $this->manager->addSubscriberInterface($eventSubscriber);
        $this->assertTrue($this->manager->hasSubscribers(self::PRE_FOO));
        $this->assertCount(2, $this->manager->getSubscribers(self::PRE_FOO));
        $this->manager->removeSubscriberInterface($eventSubscriber);
        $this->assertFalse($this->manager->hasSubscribers(self::PRE_FOO));
    }

    public function testEventReceivesTheManagerInstanceAsArgument()
    {
        $subscriber = new TestWithManager();
        $this->manager->subscribe('test', array($subscriber, 'foo'));
        $this->assertNull($subscriber->name);
        $this->assertNull($subscriber->manager);
        $this->manager->publish('test');
        $this->assertEquals('test', $subscriber->name);
        $this->assertSame($this->manager, $subscriber->manager);
    }

    /**
     * @see https://bugs.php.net/bug.php?id=62976
     *
     * This bug affects:
     *  - The PHP 5.3 branch for versions < 5.3.18
     *  - The PHP 5.4 branch for versions < 5.4.8
     *  - The PHP 5.5 branch is not affected
     *
     * @return void
     */
    public function testWorkaroundForPhpBug62976()
    {
        $manager = $this->createManager();
        $manager->subscribe('bug.62976', new CallableClass());
        $manager->unsubscribe('bug.62976', function () {
        });
        $this->assertTrue($manager->hasSubscribers('bug.62976'));
    }

    public function testHasListenersWhenAddedCallbackListenerIsRemoved()
    {
        $subscriber = function () {
        };
        $this->manager->subscribe('foo', $subscriber);
        $this->manager->unsubscribe('foo', $subscriber);
        $this->assertFalse($this->manager->hasSubscribers());
    }

    public function testGetListenersWhenAddedCallbackListenerIsRemoved()
    {
        $subscriber = function () {
        };
        $this->manager->subscribe('foo', $subscriber);
        $this->manager->unsubscribe('foo', $subscriber);
        $this->assertSame(array(), $this->manager->getSubscribers());
    }

    public function testHasListenersWithoutEventsReturnsFalseAfterHasListenersWithEventHasBeenCalled()
    {
        $this->assertFalse($this->manager->hasSubscribers('foo'));
        $this->assertFalse($this->manager->hasSubscribers());
    }

    public function testHasListenersIsLazy()
    {
        $called = 0;
        $subscriber = array(function () use (&$called) {
            ++$called;
        }, 'onFoo');
        $this->manager->subscribe('foo', $subscriber);
        $this->assertTrue($this->manager->hasSubscribers());
        $this->assertTrue($this->manager->hasSubscribers('foo'));
        $this->assertSame(0, $called);
    }

    public function testPublishLazyListener()
    {
        $called = 0;
        $factory = function () use (&$called) {
            ++$called;
            return new TestWithManager();
        };
        $this->manager->subscribe('foo', array($factory, 'foo'));
        $this->assertSame(0, $called);
        $this->manager->publish('foo', new Event());
        $this->manager->publish('foo', new Event());
        $this->assertSame(1, $called);
    }

    public function testRemoveFindsLazyListeners()
    {
        $test = new TestWithManager();
        $factory = function () use ($test) {
            return $test;
        };

        $this->manager->subscribe('foo', array($factory, 'foo'));
        $this->assertTrue($this->manager->hasSubscribers('foo'));
        $this->manager->unsubscribe('foo', array($test, 'foo'));
        $this->assertFalse($this->manager->hasSubscribers('foo'));

        $this->manager->subscribe('foo', array($test, 'foo'));
        $this->assertTrue($this->manager->hasSubscribers('foo'));
        $this->manager->unsubscribe('foo', array($factory, 'foo'));
        $this->assertFalse($this->manager->hasSubscribers('foo'));
    }

    /*
    public function testPriorityFindsLazyListeners()
    {
        $test = new TestWithManager();
        $factory = function () use ($test) { return $test; };

        $this->manager->subscribe('foo', array($factory, 'foo'), 3);
        $this->assertSame(3, $this->manager->getListenerPriority('foo', array($test, 'foo')));
        $this->manager->unsubscribe('foo', array($factory, 'foo'));

        $this->manager->subscribe('foo', array($test, 'foo'), 5);
        $this->assertSame(5, $this->manager->getListenerPriority('foo', array($factory, 'foo')));
    }
    */

    public function testGetLazyListeners()
    {
        $test = new TestWithManager();
        $factory = function () use ($test) {
            return $test;
        };

        $this->manager->subscribe('foo', array($factory, 'foo'), 3);
        $this->assertSame(array(array($test, 'foo')), $this->manager->getSubscribers('foo'));

        $this->manager->unsubscribe('foo', array($test, 'foo'));
        $this->manager->subscribe('bar', array($factory, 'foo'), 3);
        $this->assertSame(array('bar' => array(array($test, 'foo'))), $this->manager->getSubscribers());
    }

    protected function createManager()
    {
        return new Manager();
    }
}

class CallableClass
{
    public function __invoke()
    {
    }
}

class TestSubscriber
{
    public $preFooInvoked = false;
    public $postFooInvoked = false;

    /*
        Subscribe methods
    */

    public function preFoo(Event $e)
    {
        $this->preFooInvoked = true;
    }

    public function postFoo(Event $e)
    {
        $this->postFooInvoked = true;

        $e->stopPropagation();
    }
}

class TestWithManager
{
    public $name;
    public $manager;

    public function foo(Event $e, $name, $manager)
    {
        $this->name = $name;
        $this->manager = $manager;
    }
}

class TestSubscriberInterface implements SubscriberInterface
{
    public function getSubscriptions()
    {
        return array('pre.foo' => 'preFoo', 'post.foo' => 'postFoo');
    }
}

class TestSubscriberInterfaceWithPriorities implements SubscriberInterface
{
    public function getSubscriptions()
    {
        return array(
            'pre.foo' => array('preFoo', 10),
            'post.foo' => array('postFoo'),
        );
    }
}

class TestSubscriberInterfaceWithMultipleSubscribers implements SubscriberInterface
{
    public function getSubscriptions()
    {
        return array('pre.foo' => array(
            array('preFoo1'),
            array('preFoo2', 10),
        ));
    }
}
