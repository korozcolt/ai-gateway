<?php

namespace Korbytes\AiGateway\Usage;

use Closure;
use Korbytes\AiGateway\Contracts\AiProviderInterface;
use Korbytes\AiGateway\Dto\AiRequest;
use Korbytes\AiGateway\Dto\AiResponse;
use Korbytes\AiGateway\Dto\AiUsage;
use Korbytes\AiGateway\Exceptions\AiProviderException;
use Korbytes\AiGateway\ModelPrice;

/**
 * Decorator applied inside AiDriverRegistry::make(): records one ledger event per call, on
 * success and on failure, with the cost computed from the connection price at call time.
 * Never stores prompt or response content.
 */
final class MeteredProvider implements AiProviderInterface
{
    /** Unit: nanoseconds per millisecond, for the monotonic clock. */
    private const NS_PER_MS = 1000000;

    /** @param Closure(string): ?ModelPrice $priceFor price snapshot lookup by model id */
    public function __construct(
        private readonly AiProviderInterface $inner,
        private readonly int $providerId,
        private readonly Closure $priceFor,
        private readonly UsageRecorder $recorder,
    ) {}

    public function key(): string
    {
        return $this->inner->key();
    }

    public function complete(AiRequest $request): AiResponse
    {
        $started = hrtime(true);

        try {
            $response = $this->inner->complete($request);
        } catch (AiProviderException $e) {
            // Failures record the real elapsed time so timeouts are not understated in latency reports.
            $this->record($request, $e->usage ?? new AiUsage, $e->reasonCode, intdiv(hrtime(true) - $started, self::NS_PER_MS));

            throw $e;
        }

        $cost = $this->record($request, $response->usage(), 'ok', $response->latencyMs);

        return $response->withCost($cost);
    }

    private function record(AiRequest $request, AiUsage $usage, string $outcome, int $latencyMs): ?float
    {
        $price = ($this->priceFor)($request->params->model);
        $cost = $price?->cost($usage);

        $this->recorder->record([
            'ai_provider_id' => $this->providerId,
            'context_type' => $request->contextType,
            'context_id' => $request->contextId,
            'model' => $request->params->model,
            'purpose' => $request->purpose,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'thinking_tokens' => $usage->thinkingTokens,
            'cached_tokens' => $usage->cachedTokens,
            'price_input_usd_per_million' => $price?->inputUsdPerMillion,
            'price_output_usd_per_million' => $price?->outputUsdPerMillion,
            'cost_usd' => $cost,
            'reported_cost_usd' => $usage->reportedCostUsd,
            'latency_ms' => $latencyMs,
            'outcome' => $outcome,
            'occurred_at' => now()->toIso8601String(),
        ]);

        return $cost;
    }
}
