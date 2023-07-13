<?php

/**
 * Manage event subscriptions
 *
 * @package   bdk\PubSub
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2022 Brad Kent
 * @version   v2.4
 * @link      http://www.github.com/bkdotcom/PubSub
 */

namespace bdk\PubSub;

use bdk\PubSub\SubscriberInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Event publish/subscribe event manager
 */
class Manager
{
    const EVENT_PHP_SHUTDOWN = 'php.shutdown';
    const DEFAULT_PRIORITY = 0;

    private $subscribers = array();
    private $sorted = array();

    /**
     * Constructor
     */
    public function __construct()
    {
        /*
            As a convenience, make shutdown subscribeable
        */
        \register_shutdown_function(function () {
            $this->publish(self::EVENT_PHP_SHUTDOWN); // @codeCoverageIgnore
        });
    }

    /**
     * Subscribe to all of the event subscribers provided by passed object
     *
     * Calls `$interface`'s `getInterfaceSubscribers` method and subscribes accordingly
     *
     * @param SubscriberInterface $interface object implementing subscriber interface
     *
     * @return array A normalized list of subscriptions added.
     */
    public function addSubscriberInterface(SubscriberInterface $interface)
    {
        $subscribersByEvent = $this->getInterfaceSubscribers($interface);
        foreach ($subscribersByEvent as $eventName => $eventSubscribers) {
            foreach ($eventSubscribers as $subscriberInfo) {
                $this->subscribe(
                    $eventName,
                    $subscriberInfo['callable'],
                    $subscriberInfo['priority'],
                    $subscriberInfo['onlyOnce']
                );
            }
        }
        return $subscribersByEvent;
    }

    /**
     * Gets the subscribers of a specific event or all subscribers sorted by descending priority.
     *
     * If event name is not specified, subscribers for all events will be returned
     *
     * @param string $eventName The name of the event
     *
     * @return array The event subscribers for the specified event, or all event subscribers by event name
     */
    public function getSubscribers($eventName = null)
    {
        $sortFunc = function ($eventName) {
            if (!isset($this->sorted[$eventName])) {
                $this->prepSubscribers($eventName);
            }
        };
        if ($eventName !== null) {
            if (!isset($this->subscribers[$eventName])) {
                return array();
            }
            $sortFunc($eventName);
            return $this->sorted[$eventName];
        }
        // return all subscribers
        foreach (\array_keys($this->subscribers) as $eventName) {
            $sortFunc($eventName);
        }
        return \array_filter($this->sorted);
    }

    /**
     * Checks whether an event has any registered subscribers.
     *
     * @param string $eventName The name of the event
     *
     * @return bool
     */
    public function hasSubscribers($eventName = null)
    {
        if ($eventName !== null) {
            return !empty($this->subscribers[$eventName]);
        }
        foreach ($this->subscribers as $subscribers) {
            if ($subscribers) {
                return true;
            }
        }
        return false;
    }

    /**
     * Publish/Trigger/Dispatch event
     *
     * @param string $eventName      event name
     * @param mixed  $eventOrSubject passed to subscribers
     * @param array  $values         values to attach to event
     *
     * @return Event
     */
    public function publish($eventName, $eventOrSubject = null, array $values = array())
    {
        $event = $eventOrSubject instanceof Event
            ? $eventOrSubject
            : new Event($eventOrSubject, $values);
        $subscribers = $this->getSubscribers($eventName);
        $this->doPublish($eventName, $subscribers, $event);
        return $event;
    }

    /**
     * Unsubscribe from all of the event subscribers provided by passed object
     *
     * Calls `$interface`'s `getInterfaceSubscribers` method and unsubscribes accordingly
     *
     * @param SubscriberInterface $interface object implementing subscriber interface
     *
     * @return array[] normalized list of subscriptions removed.
     */
    public function removeSubscriberInterface(SubscriberInterface $interface)
    {
        $subscribersByEvent = $this->getInterfaceSubscribers($interface);
        foreach ($subscribersByEvent as $eventName => $eventSubscribers) {
            foreach ($eventSubscribers as $subscriberInfo) {
                $this->unsubscribe($eventName, $subscriberInfo['callable']);
            }
        }
        return $subscribersByEvent;
    }

    /**
     * Subscribe to event
     *
     * # Callable will receive 3 params:
     *  * Event
     *  * (string) eventName
     *  * EventManager
     *
     * # Lazy-load the subscriber
     *   It's possible to lazy load the subscriber object via a "closure factory"
     *    `array(Closure, 'methodName')` - closure returns object
     *    `array(Closure)` - closure returns object that is callable (ie has `__invoke` method)
     *   The closure will be called the first time the event occurs
     *
     * @param string         $eventName event name
     * @param callable|array $callable  callable or closure factory
     * @param int            $priority  The higher this value, the earlier we handle event
     * @param bool           $onlyOnce  (false) Auto-unsubscribe after first invocation
     *
     * @return void
     */
    public function subscribe($eventName, $callable, $priority = 0, $onlyOnce = false)
    {
        unset($this->sorted[$eventName]); // clear the sorted cache
        $this->assertCallable($callable);
        $this->subscribers[$eventName][$priority][] = array(
            'callable' => $callable,
            'onlyOnce' => $onlyOnce,
            'priority' => $priority,
        );
    }

    /**
     * Removes an event subscriber from the specified event.
     *
     * @param string         $eventName The event we're unsubscribing from
     * @param callable|array $callable  The subscriber to remove
     *
     * @return void
     */
    public function unsubscribe($eventName, $callable)
    {
        if (!isset($this->subscribers[$eventName])) {
            return;
        }
        if ($this->isClosureFactory($callable)) {
            $callable = $this->doClosureFactory($callable);
        }
        $this->prepSubscribers($eventName);
        $priorities = \array_keys($this->subscribers[$eventName]);
        foreach ($priorities as $priority) {
            $this->unsubscribeHelper($eventName, $callable, $priority, false);
        }
    }

    /**
     * Test if value is a callable or "closure factory"
     *
     * @param mixed $val Value to test
     *
     * @return void
     *
     * @throws InvalidArgumentException
     */
    private function assertCallable($val)
    {
        if (\is_callable($val, true)) {
            return;
        }
        if ($this->isClosureFactory($val)) {
            return;
        }
        throw new InvalidArgumentException(\sprintf(
            'Expected callable or "closure factory", but %s provided',
            $this->getDebugType($val)
        ));
    }

    /**
     * Instantiate the object wrapped in the closure factory
     * closure factory may be
     *    [Closure, 'methodName'] - closure returns object
     *    [Closure] - closure returns object that is callable (ie has __invoke)
     *
     * @param array $closureFactory "closure factory" lazy loads an object / subscriber
     *
     * @return callable
     */
    private function doClosureFactory($closureFactory = array())
    {
        $closureFactory[0] = $closureFactory[0]($this);
        return \count($closureFactory) === 1
            ? $closureFactory[0]    // invokeable object
            : $closureFactory;      // [obj, 'method']
    }

    /**
     * Calls the subscribers of an event.
     *
     * @param string     $eventName   The name of the event to publish
     * @param callable[] $subscribers The event subscribers
     * @param Event      $event       The event object to pass to the subscribers
     *
     * @return void
     */
    protected function doPublish($eventName, $subscribers, Event $event)
    {
        foreach ($subscribers as $subscriberInfo) {
            if ($event->isPropagationStopped()) {
                break;
            }
            \call_user_func($subscriberInfo['callable'], $event, $eventName, $this);
            if ($subscriberInfo['onlyOnce']) {
                $this->unsubscribeHelper($eventName, $subscriberInfo['callable'], $subscriberInfo['priority'], true);
            }
        }
    }

    /**
     * Gets the type name of a variable in a way that is suitable for debugging
     *
     * @param mixed $value Value to inspect
     *
     * @return string
     */
    private static function getDebugType($value)
    {
        return \is_object($value)
            ? \get_class($value)
            : \gettype($value);
    }

    /**
     * Does val appear to be a "closure factory"?
     * array & array[0] instanceof Closure
     *
     * @param mixed $val value to check
     *
     * @return bool
     *
     * @psalm-assert-if-true array $val
     */
    private function isClosureFactory($val)
    {
        return \is_array($val) && isset($val[0]) && $val[0] instanceof \Closure;
    }

    /**
     * Calls the passed object's getSubscriptions() method and returns a normalized list of subscriptions
     *
     * @param SubscriberInterface $interface SubscriberInterface instance
     *
     * @return array
     *
     * @throws RuntimeException
     */
    private function getInterfaceSubscribers(SubscriberInterface $interface)
    {
        $subscriptions = $interface->getSubscriptions();
        if (\is_array($subscriptions) === false) {
            throw new RuntimeException(\sprintf(
                'Expected array from %s::getSubscriptions().  Got %s',
                \get_class($interface),
                $this->getDebugType($subscriptions)
            ));
        }
        foreach ($subscriptions as $eventName => $mixed) {
            $eventSubscribers = $this->normalizeInterfaceSubscribers($interface, $mixed);
            if ($eventSubscribers === false) {
                throw new RuntimeException(\sprintf(
                    '%s::getSubscriptions():  Unexpected subscriber(s) defined for %s',
                    \get_class($interface),
                    $eventName
                ));
            }
            $subscriptions[$eventName] = $eventSubscribers;
        }
        return $subscriptions;
    }

    /**
     * Normalize event subscribers
     *
     * @param SubscriberInterface $interface SubscriberInterface instance
     * @param string|array        $mixed     method(s) with optional priority/onlyOnce
     *
     * @return array|false list of array(methodName, priority)
     */
    private function normalizeInterfaceSubscribers(SubscriberInterface $interface, $mixed)
    {
        // test if single subscriber
        $subscriberInfo = $this->normalizeInterfaceSubscriber($mixed);
        if ($subscriberInfo) {
            $subscriberInfo['callable'] = array($interface, $subscriberInfo['callable']);
            return array($subscriberInfo);
        }
        if (\is_array($mixed) === false) {
            return false;
        }
        // multiple subscribers
        $eventSubscribers = array();
        foreach ($mixed as $mixed2) {
            $subscriberInfo = $this->normalizeInterfaceSubscriber($mixed2);
            if ($subscriberInfo) {
                $subscriberInfo['callable'] = array($interface, $subscriberInfo['callable']);
                $eventSubscribers[] = $subscriberInfo;
                continue;
            }
            return false;
        }
        return $eventSubscribers;
    }

    /**
     * Test if value defines method/priority/onlyOnce
     *
     * @param string|array $mixed method/priority/onlyOnce info
     *
     * @return array|false
     */
    private function normalizeInterfaceSubscriber($mixed)
    {
        $subscriberInfo = array(
            'callable' => null,
            'onlyOnce' => false,
            'priority' => self::DEFAULT_PRIORITY,
        );
        if (\is_string($mixed)) {
            $subscriberInfo['callable'] = $mixed;
            return $subscriberInfo;
        }
        if (\is_array($mixed)) {
            $subscriberInfo = $this->normalizeInterfaceSubscriberArray($mixed, $subscriberInfo);
        }
        return $subscriberInfo['callable'] !== null
            ? $subscriberInfo
            : false;
    }

    /**
     * Test if given array defines method/priority/onlyOnce
     *
     * @param array $mixed          array values
     * @param array $subscriberInfo [description]
     *
     * @return array updated subscriberInfo
     */
    private function normalizeInterfaceSubscriberArray(array $mixed, array $subscriberInfo)
    {
        $tests = array(
            'callable' => 'is_string',
            'onlyOnce' => 'is_bool',
            'priority' => 'is_int',
        );
        while ($mixed && $tests) {
            $val = \array_shift($mixed);
            foreach ($tests as $key => $test) {
                if ($test($val)) {
                    $subscriberInfo[$key] = $val;
                    unset($tests[$key]);
                    continue 2;
                }
            }
            // all tests failed for current value
            $subscriberInfo['callable'] = null;
            break;
        }
        return $subscriberInfo;
    }

    /**
     * Sorts the internal list of subscribers for the given event by priority.
     * Any closure factories for eventName are invoked
     *
     * @param string $eventName The name of the event
     *
     * @return void
     */
    private function prepSubscribers($eventName)
    {
        \krsort($this->subscribers[$eventName]);
        $this->sorted[$eventName] = array();
        foreach ($this->subscribers[$eventName] as $priority => $eventSubscribers) {
            foreach ($eventSubscribers as $k => $subscriberInfo) {
                if ($this->isClosureFactory($subscriberInfo['callable'])) {
                    $subscriberInfo['callable'] = $this->doClosureFactory($subscriberInfo['callable']);
                    $this->subscribers[$eventName][$priority][$k] = $subscriberInfo;
                }
                $this->sorted[$eventName][] = $subscriberInfo;
            }
        }
    }

    /**
     * Find callable in eventName/priority array and remove it
     *
     * @param string   $eventName The event we're unsubscribing from
     * @param callable $callable  callable
     * @param int      $priority  The priority
     * @param bool     $onlyOnce  Only unsubscribe "onlyOnce" subscribers
     *
     * @return void
     */
    private function unsubscribeHelper($eventName, $callable, $priority, $onlyOnce)
    {
        foreach ($this->subscribers[$eventName][$priority] as $k => $subscriberInfo) {
            $search = \array_filter(array(
                'callable' => $callable,
                'onlyOnce' => $onlyOnce,
            ));
            if (\array_intersect_key($subscriberInfo, $search) !== $search) {
                continue;
            }
            unset($this->subscribers[$eventName][$priority][$k], $this->sorted[$eventName]);
            if ($onlyOnce) {
                break;
            }
        }
        if (empty($this->subscribers[$eventName][$priority])) {
            unset($this->subscribers[$eventName][$priority]);
        }
    }
}
