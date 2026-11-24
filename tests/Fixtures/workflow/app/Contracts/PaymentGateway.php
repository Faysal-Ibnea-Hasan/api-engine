<?php

namespace WorkflowFixture\App\Contracts;

interface PaymentGateway
{
    public function charge($order): bool;
}
