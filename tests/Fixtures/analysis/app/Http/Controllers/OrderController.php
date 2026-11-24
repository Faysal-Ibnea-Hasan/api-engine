<?php

namespace Fixture\App\Http\Controllers;

use Fixture\App\Http\Requests\DelegatedOrderRequest;
use Fixture\App\Http\Requests\DynamicRequest;
use Fixture\App\Http\Requests\StoreOrderRequest;
use Fixture\App\Http\Resources\OrderResource;
use Fixture\App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrderController
{
    public function __construct(private $service)
    {
        $this->middleware('auth:sanctum')->except('index');
        $this->middleware('can:manage-orders')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $perPage = $request->query('per_page');
        $search = $request->input('search');

        return OrderResource::collection(Order::query()->paginate($perPage));
    }

    public function store(StoreOrderRequest $request)
    {
        $order = Order::create($request->validated());

        return (new OrderResource($order))->response()->setStatusCode(201);
    }

    public function show(Order $order)
    {
        return new OrderResource($order);
    }

    public function update(Request $request, $order)
    {
        $rules = ['note' => 'nullable|string|max:500'];
        $rules['priority'] = 'sometimes|integer|between:1,5';

        $request->validate($rules);

        abort_if($request->header('X-Lock') === 'on', 423);

        return response()->json(['message' => 'Updated', 'ok' => true], 200);
    }

    public function legacy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 403);
        }

        $receipt = $request->file('receipt');

        return response()->json(['message' => 'Saved']);
    }

    public function cancel(Order $order)
    {
        abort_unless($order->cancellable, 404, 'Order not found');

        return response()->json(['cancelled' => true]);
    }

    public function destroy(Order $order)
    {
        $order->delete();

        return response()->noContent();
    }

    public function otp(Request $request)
    {
        $headers = ["X-Key: {$request->bearerToken()}", 'Accept: application/json'];

        $validator = Validator::make($request->all(), $this->otpRules());
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 403);
        }

        return response()->json(['sent' => true]);
    }

    private function otpRules(): array
    {
        return ['phone' => 'required|string', 'channel' => 'in:sms,email'];
    }

    public function dynamic(DynamicRequest $request)
    {
        return $this->service->handle($request->{$request->field});
    }

    public function delegated(DelegatedOrderRequest $request)
    {
        return response()->json(['saved' => true]);
    }
}
