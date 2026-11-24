<?php

namespace WorkflowFixture\App\Contracts;

interface ShippingQuotes
{
    public function quote($order): int;
}
