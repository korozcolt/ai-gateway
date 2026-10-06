<?php

namespace Korbytes\AiGateway\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One metered AI driver call. Append-only ledger; context_type/context_id optionally attribute the call to an
 * app entity (a case, a tenant...). Never stores prompt or response text.
 */
#[Fillable([
    'ai_provider_id', 'context_type', 'context_id', 'model', 'purpose', 'input_tokens', 'output_tokens', 'thinking_tokens',
    'cached_tokens', 'price_input_usd_per_million', 'price_output_usd_per_million', 'cost_usd', 'reported_cost_usd', 'latency_ms',
    'outcome', 'occurred_at',
])]
class AiUsageEvent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'cost_usd' => 'decimal:8',
            'reported_cost_usd' => 'decimal:8',
        ];
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }
}
