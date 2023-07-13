<?php

namespace bdk\PubSubTests\Fixture;

use bdk\PubSub\SubscriberInterface as SubInterface;

class SubscriberInterface implements SubInterface
{
    public $getSubscriptionsReturn = array(
        'pre.foo' => 'preFoo',
        'post.foo' => 'postFoo',
    );

    public function getSubscriptions()
    {
        return $this->getSubscriptionsReturn;
    }
}
