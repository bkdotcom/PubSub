<?php

namespace bdk\PubSubTests\Fixture;

use bdk\PubSub\Event;
use bdk\PubSub\Manager;

class WithManager
{
    public $name;
    public $manager;

    public function foo(Event $e, $name, Manager $manager)
    {
        $this->name = $name;
        $this->manager = $manager;
    }
}
