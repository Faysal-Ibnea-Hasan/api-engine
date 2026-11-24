<?php

namespace ContractFixture\App\Http\Requests;

use ContractFixture\App\Support\Envelope;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreProductRequest extends FormRequest
{
    public function rules(): array
    {
        return ['name' => 'required|string|max:80', 'price' => 'required|numeric|min:1', 'kind' => 'required|in:food,drink'];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(
            Envelope::make(config('response.unprocessable_entity_422'), null, Envelope::errorList($validator->errors()->getMessages())),
            422,
        ));
    }
}
