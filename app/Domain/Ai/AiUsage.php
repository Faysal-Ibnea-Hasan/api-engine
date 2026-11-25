<?php

namespace App\Domain\Ai;

use App\Models\AiProvider;
use App\Models\AiRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Token usage of one provider in its current limit period (calendar day or month, app timezone). */
final class AiUsage
{
    /** @return array{used: int, limit: int|null, left: int|null, resetsAt: string|null} */
    public static function meter(AiProvider $provider, ?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now())->copy()->setTimezone(config('app.timezone'));
        $daily = $provider->limit_period === 'day';
        $start = $daily ? $now->copy()->startOfDay() : $now->copy()->startOfMonth();

        $used = (int) AiRun::query()
            ->where('ai_provider_id', $provider->id)
            ->where('created_at', '>=', $start)
            ->sum(DB::raw('input_tokens + output_tokens'));

        $limit = $provider->limit_tokens;

        return [
            'used' => $used,
            'limit' => $limit,
            'left' => $limit === null ? null : max(0, $limit - $used),
            'resetsAt' => $limit === null ? null : ($daily ? $start->addDay() : $start->addMonth())->toIso8601String(),
        ];
    }
}
