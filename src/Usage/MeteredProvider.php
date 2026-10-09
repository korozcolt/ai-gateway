<?php

namespace Korbytes\AiGateway\Usage;

use Closure;
use Korbytes\AiGateway\AiFailure;
use Korbytes\AiGateway\Contracts\AiProviderInterface;
use Korbytes\AiGateway\Contracts\ListsModels;
use Korbytes\AiGateway\Dto\AiRequest;
use Korbytes\AiGateway\Dto\AiResponse;
use Korbytes\AiGateway\Dto\AiUsage;
use Korbytes\AiGateway\Exceptions\AiProviderException;
use Korbytes\AiGateway\ModelPrice;
use Throwable;

/**
 * Decorator applied inside AiDriverRegistry::make(): records one ledger event per call, on
 * success and on failure, with the cost computed from the connection price at call time.
 * Never stores prompt or response content.
 */
final class MeteredProvider implements AiProviderInterface, ListsModels
{
    /** Unit: nanoseconds per millisecond, for the monotonic clock. */
    private const NS_PER_MS = 1000000;

    /** @param Closure(string): ?ModelPrice $priceFor price snapshot lookup by model id */
    public function __construct(
        private readonly AiProviderInterface $inner,
        private readonly int $providerId,
        private readonly Closure $priceFor,
        private readonly UsageRecorder $recorder,
        /** @var list<string> model ids the connection declares; a call for any other model is rejected before it is sent */
        private readonly array $allowedModels = [],
    ) {}

    public function key(): string
    {
        return $this->inner->key();
    }

    public function complete(AiRequest $request): AiResponse
    {
        $started = hrtime(true);

        if (! in_array($request->params->model, $this->allowedModels, true)) {
            $this->record($request, new AiUsage, AiFailure::UnknownModel->value, 0);

            throw new AiProviderException(AiFailure::UnknownModel);
        }

        try {
            $response = $this->inner->complete($request);
        } catch (AiProviderException $e) {
            // Failures record the real elapsed time so timeouts are not understated in latency reports.
            $this->record($request, $e->usage ?? new AiUsage, $e->reasonCode, intdiv(hrtime(true) - $started, self::NS_PER_MS));

            throw $e;
        } catch (Throwable $e) {
            // Any other failure of the inner driver still leaves a ledger row; the original exception is rethrown.
            $this->record($request, new AiUsage, 'internal_error', intdiv(hrtime(true) - $started, self::NS_PER_MS));

            throw $e;
        }

        $cost = $this->record($request, $response->usage(), 'ok', $response->latencyMs);

        return $response->withCost($cost);
    }

    /**
     * Delegates to the inner driver when it can list models. Listing is not metered.
     *
     * @return list<string>
     *
     * @throws AiProviderException invalid_request when the driver cannot list models
     */
    public function listModels(): array
    {
        if (! $this->inner instanceof ListsModels) {
            throw new AiProviderException(AiFailure::InvalidRequest);
        }

        return $this->inner->listModels();
    }

    private function record(AiRequest $request, AiUsage $usage, string $outcome, int $latencyMs): ?float
    {
        try {
            $price = ($this->priceFor)($request->params->model);
            $cost = $price?->cost($usage);
        } catch (Throwable $e) {
            // A malformed price must never replace the call result or the original exception.
            report($e);
            $price = null;
            $cost = null;
        }

        $this->recorder->record([
            'ai_provider_id' => $this->providerId,
            'context_type' => $request->contextType === null ? null : mb_substr($request->contextType, 0, 60),
            'context_id' => $request->contextId,
            // Truncated to the column widths: an over-long app label must not make the queued insert fail.
            'model' => mb_substr($request->params->model, 0, 120),
            'purpose' => mb_substr($request->purpose, 0, 40),
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
