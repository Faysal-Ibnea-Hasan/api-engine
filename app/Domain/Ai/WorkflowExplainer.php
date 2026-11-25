<?php

namespace App\Domain\Ai;

use App\Domain\Audit\AuditLog;
use App\Models\AiProvider;
use App\Models\AiRun;
use App\Models\User;
use App\Models\WorkflowExplanation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** The AI step of an explanation (spec § 6.5 step 4): re-checks the gate and key, calls the provider, meters the call. */
final class WorkflowExplainer
{
    private const MAX_OUTPUT_TOKENS = 16000;

    /** @param  bool  $canRetry  a retryable failure is rethrown (row back to queued) so the queue tries again */
    public function run(int $explanationId, bool $canRetry): void
    {
        $claimed = WorkflowExplanation::query()->whereKey($explanationId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return; // finished, failed or re-requested meanwhile
        }

        $explanation = WorkflowExplanation::query()->with('analysis.endpoint')->findOrFail($explanationId);

        $gate = AiGate::check();
        $provider = $gate['provider'];
        if (! $gate['available'] || $provider === null || $provider->id !== $explanation->ai_provider_id) {
            $this->finish($explanation, $gate['code'] === 'limit_reached' ? 'ai.limit_reached' : 'ai.unavailable');

            return;
        }
        if ($provider->key_version !== $explanation->key_version) {
            $this->finish($explanation, 'ai.key_changed');

            return;
        }

        $context = WorkflowContext::build($explanation->analysis);
        $system = WorkflowPrompt::system();
        // Metered when the provider reports no input usage (timeout, or an unverified usage shape): roughly 4 bytes a token.
        $estimate = intdiv(strlen($system.$context['user']), 4);
        $model = (string) $explanation->model;
        $effort = (string) $explanation->effort;
        $entry = collect($provider->available_models ?? [])->firstWhere('id', $model);
        $started = hrtime(true);
        $generation = null;

        try {
            $generation = AiAdapters::for($provider->provider)->generate(
                (string) $provider->api_key, $model, ($entry['effort'] ?? true) ? $effort : '',
                $system, $context['user'], self::MAX_OUTPUT_TOKENS,
            );
            $input = $generation->inputTokens ?: $estimate;

            DB::transaction(function () use ($explanation, $started, $generation, $input, $context, $provider, $model) {
                $run = $this->record($explanation, $started, $input, $generation->outputTokens, null);
                $explanation->forceFill([
                    'status' => 'completed',
                    'markdown' => $generation->text,
                    'truncated' => $generation->truncated,
                    'omitted' => $context['omitted'],
                    'prompt_version' => WorkflowPrompt::VERSION,
                    'ai_run_id' => $run->id,
                    'finished_at' => now(),
                ])->save();

                AuditLog::record('ai.workflow_explained', $explanation->workspace, User::query()->find($explanation->requested_by), $explanation, [
                    'analysis_id' => $explanation->endpoint_analysis_id,
                    'provider' => $provider->provider,
                    'model' => $model,
                    'input_units' => $input,
                    'output_units' => $generation->outputTokens,
                ]);
            });
        } catch (AiError $e) {
            $this->record($explanation, $started, $e->noResponse ? $estimate : $e->inputTokens, $e->outputTokens, $e->errorCode());
            if ($e->providerStatus() !== null) {
                // Only for the key that failed: an admin may have replaced and re-tested it during the call.
                AiProvider::query()->whereKey($provider->id)->where('key_version', $explanation->key_version)
                    ->update(['status' => $e->providerStatus(), 'updated_at' => now()]);
            }
            if ($e->retryable() && $canRetry) {
                $explanation->forceFill(['status' => 'queued'])->save();

                // A fresh exception: the worker reports it, and the adapter frames in $e's trace carry the key as an argument.
                throw AiError::of($e->errorCode());
            }
            $this->finish($explanation, $e->errorCode());
        } catch (Throwable $e) {
            // Never rethrown: a queue retry would call (and bill) the provider again. Class and place only: no trace, whose frames may hold the key.
            Log::error('Workflow explanation failed unexpectedly.', ['explanation_id' => $explanationId, 'exception' => $e::class, 'message' => $e->getMessage(), 'at' => $e->getFile().':'.$e->getLine()]);
            rescue(fn () => $this->record($explanation, $started, $generation?->inputTokens ?: $estimate, $generation->outputTokens ?? 0, 'ai.internal'));
            // A query, not the model: after a failed save or a rolled-back transaction the model's attributes are not what is stored.
            WorkflowExplanation::query()->whereKey($explanationId)
                ->update(['status' => 'failed', 'failure_code' => 'ai.internal', 'finished_at' => now(), 'updated_at' => now()]);
        }
    }

    private function finish(WorkflowExplanation $explanation, string $code): void
    {
        $explanation->forceFill(['status' => 'failed', 'failure_code' => $code, 'finished_at' => now()])->save();
    }

    /** One usage-ledger row per provider call, failed ones included. */
    private function record(WorkflowExplanation $explanation, int $started, int $input, int $output, ?string $failure): AiRun
    {
        $run = new AiRun;
        $run->forceFill([
            'ai_provider_id' => $explanation->ai_provider_id,
            'provider' => (string) $explanation->provider,
            'model' => (string) $explanation->model,
            'effort' => (string) $explanation->effort,
            'workspace_id' => $explanation->workspace_id,
            'project_id' => $explanation->project_id,
            'endpoint_analysis_id' => $explanation->endpoint_analysis_id,
            'user_id' => $explanation->requested_by,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'status' => $failure === null ? 'succeeded' : 'failed',
            'failure_code' => $failure,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'prompt_version' => WorkflowPrompt::VERSION,
        ])->save();

        return $run;
    }
}
