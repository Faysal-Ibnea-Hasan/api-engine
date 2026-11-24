<?php

namespace ContractFixture\App\Http\Resources;

use Illuminate\Http\Request;

class ProductResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'id' => (int) $this->resource['id'],
            'name' => $this->resource['name'] ?? null,
            'tags' => ['featured' => (bool) ($this->resource['featured'] ?? false)],
            'currency' => 'USD',
        ]);
    }
}
