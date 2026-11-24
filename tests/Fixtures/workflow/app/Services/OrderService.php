<?php

namespace WorkflowFixture\App\Services;

use WorkflowFixture\App\Events\OrderPlaced;
use WorkflowFixture\App\Jobs\ProcessOrder;
use WorkflowFixture\App\Jobs\SendInvoice;
use WorkflowFixture\App\Models\Order;

class OrderService
{
    public function place(array $data): Order
    {
        $order = Order::create($data);

        if (request('express')) {
            SendInvoice::dispatch($order);
        }
        ProcessOrder::dispatch($order);
        event(new OrderPlaced($order));
        $this->audit($order);

        return $order;
    }

    private function audit(Order $order): void
    {
        $order->items()->create(['note' => 'placed']);
    }

    public function ping(): int
    {
        return Order::query()->count();
    }

    public function walk(int $n): int
    {
        return $this->back($n);
    }

    private function back(int $n): int
    {
        return $this->walk($n);
    }
}
