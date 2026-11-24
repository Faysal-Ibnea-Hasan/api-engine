<?php

namespace WorkflowFixture\App\Http\Controllers;

use WorkflowFixture\App\Contracts\PaymentGateway;
use WorkflowFixture\App\Contracts\ShippingQuotes;
use WorkflowFixture\App\Http\Requests\StoreOrderRequest;
use WorkflowFixture\App\Http\Resources\OrderResource;
use WorkflowFixture\App\Models\Order;
use WorkflowFixture\App\Services\DeepChain;
use WorkflowFixture\App\Services\MissingService;
use WorkflowFixture\App\Services\OrderService;

class OrderController
{
    public function __construct(
        private OrderService $orders,
        private PaymentGateway $gateway,
        private ShippingQuotes $shipping,
        private MissingService $missing, // not in the snapshot
    ) {}

    public function store(StoreOrderRequest $request)
    {
        $order = $this->orders->place($request->validated());
        $this->orders->place($request->validated()); // the same call again: one edge, not two

        return new OrderResource($order);
    }

    public function show(int $id)
    {
        return new OrderResource(Order::with('items')->findOrFail($id));
    }

    public function charge(int $id)
    {
        $order = Order::findOrFail($id);
        $this->gateway->charge($order);
        $this->shipping->quote($order);
        $this->missing->run();
        $action = request('action');
        $this->{$action}();

        return response()->json(['charged' => true]);
    }

    public function conditionalFirst()
    {
        if (request('again')) {
            $this->orders->ping();
        }
        $this->orders->ping();

        return response()->json(['ok' => true]);
    }

    public function unconditionalFirst()
    {
        $this->orders->ping();
        if (request('again')) {
            $this->orders->ping();
        }

        return response()->json(['ok' => true]);
    }

    public function onlyConditional()
    {
        if (request('maybe')) {
            $this->orders->ping();
        }

        return response()->json(['ok' => true]);
    }

    public function recurse()
    {
        return response()->json(['depth' => $this->orders->walk(3)]);
    }

    public function deep()
    {
        return response()->json(['value' => (new DeepChain)->d1()]);
    }
}
