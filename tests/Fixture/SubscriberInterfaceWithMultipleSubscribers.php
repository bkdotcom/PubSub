<?php

namespace bdk\PubSubTests\Fixture;

use bdk\PubSub\SubscriberInterface;

class SubscriberInterfaceWithMultipleSubscribers implements SubscriberInterface
{
    public function getSubscriptions()
    {
        return array(
            'pre.foo' => array(
                array('preFoo1'),
                array('preFoo2', 10),
            ),
        );
    }
}
