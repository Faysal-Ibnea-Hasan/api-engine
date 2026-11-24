<?php

namespace WorkflowFixture\App\Services;

use WorkflowFixture\App\Contracts\ShippingQuotes;

class FlatRateShipping implements ShippingQuotes
{
    public function quote($order): int
    {
        return 5;
    }
}
