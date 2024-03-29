<?php

/**
 * Manage event subscriptions
 *
 * @package   bdk\PubSub
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2023 Brad Kent
 * @version   v3.1
 * @link      http://www.github.com/bkdotcom/PubSub
 */

namespace bdk\PubSub;

use bdk\PubSub\SubscriberInterface;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Support methods for manager
 */
trait ManagerHelperTrait
{
    /**
     * Test if value is a callable or "closure factory"
     *
     * @param mixed $val Value to test
     *
     * @return void
     *
     * @throws InvalidArgumentException
     */
    private static function assertCallable($val)
    {
        if (\is_callable($val, true)) {
            return;
        }
        if (self::isClosureFactory($val)) {
            return;
        }
        throw new InvalidArgumentException(\sprintf(
            'Expected callable or "closure factory", but %s provided',
            self::getDebugType($val)
        ));
    }

    /**
     * As a convenience, we'll attach subscriber's return value to event['return']
     *
     *     * Event must already have 'return' value defined and must be `null` or ""
     *
     * @param mixed $return return value
     * @param Event $event  Event instance
     *
     * @return void
     */
    private function attachReturnToEvent($return, Event $event)
    {
        if ($return === null) {
            return;
        }
        if (\in_array($event['return'], array(null, ''), true) === false) {
            // event already has non-null return value
            return;
        }
        if (\array_key_exists('return', $event->getValues()) === false) {
            // return value not defined / not expected to be set
            return;
        }
        $event['return'] = $return;
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
     * Calls the passed object's getSubscriptions() method and returns a normalized list of subscriptions
     *
     * @param SubscriberInterface $interface SubscriberInterface instance
     *
     * @return array
     *
     * @throws RuntimeException
     */
    private static function getInterfaceSubscribers(SubscriberInterface $interface)
    {
        $subscriptions = $interface->getSubscriptions();
        if (\is_array($subscriptions) === false) {
            throw new RuntimeException(\sprintf(
                'Expected array from %s::getSubscriptions().  Got %s',
                \get_class($interface),
                self::getDebugType($subscriptions)
            ));
        }
        foreach ($subscriptions as $eventName => $mixed) {
            $eventSubscribers = self::normalizeInterfaceSubscribers($interface, $mixed);
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
     * Does val appear to be a "closure factory"?
     * array & array[0] instanceof Closure
     *
     * @param mixed $val value to check
     *
     * @return bool
     *
     * @psalm-assert-if-true array $val
     */
    private static function isClosureFactory($val)
    {
        return \is_array($val) && isset($val[0]) && $val[0] instanceof Closure;
    }

    /**
     * Normalize event subscribers
     *
     * @param SubscriberInterface $interface SubscriberInterface instance
     * @param string|array        $mixed     method(s) with optional priority/onlyOnce
     *
     * @return array|false list of array(methodName, priority)
     */
    private static function normalizeInterfaceSubscribers(SubscriberInterface $interface, $mixed)
    {
        // test if single subscriber
        $subscriberInfo = self::normalizeInterfaceSubscriber($interface, $mixed);
        if ($subscriberInfo) {
            return array($subscriberInfo);
        }
        if (\is_array($mixed) === false) {
            return false;
        }
        // multiple subscribers
        $eventSubscribers = array();
        foreach ($mixed as $mixed2) {
            $subscriberInfo = self::normalizeInterfaceSubscriber($interface, $mixed2);
            if ($subscriberInfo) {
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
     * @param SubscriberInterface  $interface SubscriberInterface instance
     * @param array|string|Closure $mixed     method/priority/onlyOnce info
     *
     * @return array|false
     */
    private static function normalizeInterfaceSubscriber(SubscriberInterface $interface, $mixed)
    {
        $subscriberInfo = array(
            'callable' => null,
            'onlyOnce' => false,
            'priority' => self::DEFAULT_PRIORITY,
        );
        if (\is_string($mixed)) {
            $subscriberInfo['callable'] = array($interface, $mixed);
            return $subscriberInfo;
        }
        if ($mixed instanceof Closure) {
            $subscriberInfo['callable'] = $mixed;
            return $subscriberInfo;
        }
        if (\is_array($mixed)) {
            $subscriberInfo = self::normalizeInterfaceSubscriberArray($interface, $mixed, $subscriberInfo);
        }
        return $subscriberInfo['callable'] !== null
            ? $subscriberInfo
            : false;
    }

    /**
     * Test if given array defines method/priority/onlyOnce
     *
     * @param SubscriberInterface $interface      SubscriberInterface instance
     * @param array               $values         array values
     * @param array               $subscriberInfo default subscriberInfo values
     *
     * @return array updated subscriberInfo
     */
    private static function normalizeInterfaceSubscriberArray(SubscriberInterface $interface, array $values, array $subscriberInfo)
    {
        $tests = array(
            'callable' => static function ($val) {
                return \is_string($val) || ($val instanceof Closure);
            },
            'onlyOnce' => 'is_bool',
            'priority' => 'is_int',
        );
        while ($values && $tests) {
            $val = \array_shift($values);
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
        if (\is_string($subscriberInfo['callable'])) {
            $subscriberInfo['callable'] = array($interface, $subscriberInfo['callable']);
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
        if (!isset($this->subscribers[$eventName])) {
            $this->subscribers[$eventName] = array();
        }
        \krsort($this->subscribers[$eventName]);
        $this->sorted[$eventName] = array();
        $priorities = \array_keys($this->subscribers[$eventName]);
        \array_map(function ($priority) use ($eventName) {
            $eventSubscribers = $this->subscribers[$eventName][$priority];
            foreach ($eventSubscribers as $k => $subscriberInfo) {
                if ($this->isClosureFactory($subscriberInfo['callable'])) {
                    $subscriberInfo['callable'] = $this->doClosureFactory($subscriberInfo['callable']);
                    $this->subscribers[$eventName][$priority][$k] = $subscriberInfo;
                }
                $this->sorted[$eventName][] = $subscriberInfo;
            }
        }, $priorities);
    }

    /**
     * Prep and sort subscribers for the specified event name
     *
     * @param string $eventName The name of the event
     *
     * @return void
     */
    private function setSorted($eventName)
    {
        if (!isset($this->sorted[$eventName])) {
            $this->prepSubscribers($eventName);
        }
    }

    /**
     * Add subscriber to subscriber stack
     *
     * @param int   $stackIndex        subscriberStack index to add to
     * @param array $subscriberInfoNew subscriber info (callable, priority, onlyOnce)
     *
     * @return void
     */
    private function subscribeActive($stackIndex, array $subscriberInfoNew)
    {
        $eventSubscribers = $this->subscriberStack[$stackIndex]['subscribers'];
        $priority = $subscriberInfoNew['priority'];
        foreach ($eventSubscribers as $i => $subscriberInfo) {
            if ($priority > $subscriberInfo['priority']) {
                \array_splice($this->subscriberStack[$stackIndex]['subscribers'], $i, 0, array($subscriberInfoNew));
                return;
            }
        }
        $this->subscriberStack[$stackIndex]['subscribers'][] = $subscriberInfoNew;
    }

    /**
     * Remove callable from active event subscribers
     *
     * @param int      $stackIndex subscriberStack index to add to
     * @param callable $callable   callable
     * @param int      $priority   The priority
     *
     * @return void
     */
    private function unsubscribeActive($stackIndex, $callable, $priority)
    {
        $search = \array_filter(array(
            'callable' => $callable,
            'priority' => $priority,
        ));
        $eventSubscribers = $this->subscriberStack[$stackIndex]['subscribers'];
        foreach ($eventSubscribers as $i => $subscriberInfo) {
            if (\array_intersect_key($subscriberInfo, $search) === $search) {
                \array_splice($this->subscriberStack[$stackIndex]['subscribers'], $i, 1);
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
    private function unsubscribeFromPriority($eventName, $callable, $priority, $onlyOnce)
    {
        $search = \array_filter(array(
            'callable' => $callable,
            'onlyOnce' => $onlyOnce,
        ));
        foreach ($this->subscribers[$eventName][$priority] as $k => $subscriberInfo) {
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
