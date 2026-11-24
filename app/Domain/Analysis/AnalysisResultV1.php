<?php

namespace App\Domain\Analysis;

use Illuminate\Support\Facades\Validator;

/**
 * Structural check of the AnalysisResultV1 envelope (SHARED_CONTRACTS §6) before it is
 * stored. v1 only grows by backward-compatible additions, so unknown extra keys are allowed.
 */
final class AnalysisResultV1
{
    /**
     * @param  array<string, mixed>  $result
     * @return list<string> problems; empty when valid
     */
    public static function problems(array $result): array
    {
        $validator = Validator::make($result, [
            'schema_version' => ['required', 'in:'.EndpointAnalyzer::SCHEMA_VERSION],
            'snapshot_id' => ['required', 'string'],
            'endpoint_id' => ['required', 'string'],
            'analyzer_version' => ['required', 'string'],
            'request' => ['required', 'array'],
            'request.headers' => ['present', 'array'],
            'request.headers.*.name' => ['required', 'string'],
            'request.headers.*.status' => ['required', 'in:verified,inferred,unknown'],
            'request.fields' => ['present', 'array'],
            'request.fields.*.name' => ['required', 'string'],
            'request.fields.*.location' => ['required', 'in:path,query,body,query_or_body'],
            'request.fields.*.required' => ['required', 'in:required,conditional,optional,unknown'],
            'request.fields.*.status' => ['required', 'in:verified,partial,unknown'],
            'request.fields.*.evidence' => ['present', 'array'],
            'responses' => ['present', 'array'],
            'responses.*.status' => ['nullable', 'integer', 'between:100,599'],
            'responses.*.status_confidence' => ['required', 'in:verified,inferred,unknown'],
            'responses.*.evidence' => ['present', 'array'],
            'auth' => ['required', 'array'],
            // not_detected: written by laravel-ast-v1, still valid for those stored results
            'auth.status' => ['required', 'in:required,required_unverified,guest_or_user,not_required,unknown,not_detected'],
            'auth.signals' => ['present', 'array'],
            'dependencies' => ['present', 'array'],
            'evidence' => ['present', 'array'],
            'evidence.*.id' => ['required', 'string', 'distinct'],
            'evidence.*.path' => ['required', 'string'],
            'evidence.*.lines' => ['required', 'array', 'size:2'],
            'evidence.*.lines.*' => ['integer', 'min:1'],
            'unresolved' => ['present', 'array'],
            // laravel-ast-v6+: the followed contract (absent in older stored results)
            'contract' => ['sometimes', 'array'],
            'contract.curl' => ['required_with:contract', 'string'],
            'contract.payload' => ['required_with:contract', 'array'],
            'contract.success' => ['present_with:contract', 'array'],
            'contract.success.*.status' => ['required', 'integer', 'between:100,399'],
            'contract.errors' => ['present_with:contract', 'array'],
            'contract.errors.*.triggers' => ['required', 'array', 'min:1'],
            'unresolved.*' => ['string'],
        ]);

        $problems = $validator->errors()->all();

        // Every fact may only cite evidence the result carries.
        $ids = array_column(is_array($result['evidence'] ?? null) ? $result['evidence'] : [], 'id');
        $facts = [...($result['request']['fields'] ?? []), ...($result['request']['headers'] ?? []), ...($result['responses'] ?? []), ...($result['auth']['signals'] ?? [])];
        foreach ($facts as $fact) {
            foreach (is_array($fact['evidence'] ?? null) ? $fact['evidence'] : [] as $ref) {
                in_array($ref, $ids, true) || $problems[] = "Evidence {$ref} is cited but not present.";
            }
        }

        return array_values(array_unique($problems));
    }
}
