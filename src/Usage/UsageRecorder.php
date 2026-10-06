<?php

namespace Korbytes\AiGateway\Usage;

use Throwable;

/** Dispatches the ledger write. A recorder failure never changes the call result. */
final class UsageRecorder
{
    /** @param array<string, int|float|string|null> $event scalar fields only */
    public function record(array $event): void
    {
        try {
            $pending = RecordAiUsageEvent::dispatch($event);

            if (is_string($queue = config('ai-gateway.usage.queue'))) {
                $pending->onQueue($queue);
            }

            // The dispatch runs when the pending dispatch is destroyed: do it inside the try block.
            unset($pending);
        } catch (Throwable $e) {
            // Reported without content: the payload holds counters only.
            report($e);
        }
    }
}
