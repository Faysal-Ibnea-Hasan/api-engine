<?php

namespace ContractFixture\App\Http\Controllers;

use ContractFixture\App\Http\Requests\StoreProductRequest;
use ContractFixture\App\Http\Resources\ProductResource;

class ProductController extends BaseApiController
{
    public function show()
    {
        return $this->responseFormatter(config('response.default_200'), new ProductResource(['id' => '7', 'name' => 'Tea', 'featured' => 1]));
    }

    public function ping()
    {
        return response()->json(['pong' => true, 'version' => 2]);
    }

    public function saved()
    {
        return $this->responseFormatter(['message' => 'Saved'] + config('response.default_200'));
    }

    public function store(StoreProductRequest $request)
    {
        return $this->responseFormatter(config('response.default_store_201'), ['name' => $request->input('name')]);
    }

    public function missing()
    {
        abort(404);
    }
}
