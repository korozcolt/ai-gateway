<?php

namespace Korbytes\AiGateway\Usage;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Korbytes\AiGateway\Models\AiUsageEvent;

/**
 * Writes one ledger row. Queued so the row survives a rolled-back caller transaction
 * (a synchronous insert inside it would vanish). The payload is scalar event fields only:
 * no prompt, no response, no key.
 */
class RecordAiUsageEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    /** Never wait for the caller's transaction: the row must exist even when the caller rolls back. */
    public bool $afterCommit = false;

    /** @param array<string, int|float|string|null> $event */
    public function __construct(public array $event) {}

    public function handle(): void
    {
        AiUsageEvent::query()->create($this->event);
    }
}
