<?php

namespace bdk\PubSubTests\Fixture;

use bdk\PubSub\ValueStore as ValueStoreBase;

class ValueStore extends ValueStoreBase
{
    public $onSetArgs = array();

    protected function getFoo()
    {
        return 'bar';
    }

    protected function onSet($values = array())
    {
        $this->onSetArgs[] = $values;
    }
}
