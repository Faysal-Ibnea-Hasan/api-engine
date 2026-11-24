<?php

namespace Fixture\App\Http\Requests;

class DelegatedOrderRequest extends AppBaseRequest
{
    public static function orderRules(mixed $request): array
    {
        return [
            'email' => 'required|email',
            'guest_id' => $request->user ? 'nullable' : 'required',
        ];
    }

    public function rules(): array
    {
        return self::orderRules($this);
    }
}
