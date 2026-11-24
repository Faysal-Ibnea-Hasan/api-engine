<?php

namespace WorkflowFixture\App\Events;

class OrderPlaced
{
    public function __construct(public $order) {}
}
