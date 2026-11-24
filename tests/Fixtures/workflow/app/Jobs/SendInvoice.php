<?php

namespace WorkflowFixture\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendInvoice implements ShouldQueue
{
    use Queueable;

    public function __construct(public $order) {}
}
