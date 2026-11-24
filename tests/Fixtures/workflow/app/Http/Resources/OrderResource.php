<?php

namespace WorkflowFixture\App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'total' => $this->total,
            'items' => $this->whenLoaded('items'),
            'secret' => $this->when($request->boolean('admin'), 'shh'),
            $this->mergeWhen($request->boolean('detailed'), ['notes' => $this->notes]),
        ];
    }
}
