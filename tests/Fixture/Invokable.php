<?php

namespace bdk\PubSubTests\Fixture;

class Invokable
{
    private $outString = '';

    public function __construct($outString)
    {
        $this->outString = $outString;
    }

    public function __invoke()
    {
        echo $this->outString;
    }
}
