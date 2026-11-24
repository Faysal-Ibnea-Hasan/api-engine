<?php

namespace WorkflowFixture\App\Services;

use WorkflowFixture\App\Contracts\PaymentGateway;

/** One implementation among possibly many: the analyzer must not pick it for the interface. */
class StripeGateway implements PaymentGateway
{
    public function charge($order): bool
    {
        return true;
    }
}
