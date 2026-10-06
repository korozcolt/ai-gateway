<?php

namespace Korbytes\AiGateway\Dto;

use Korbytes\AiGateway\AiFinishReason;

final readonly class AiResponse
{
    /** @param array<string, mixed>|null $json */
    public function __construct(
        public string $text,
        public ?array $json,
        public int $inputTokens,
        public int $outputTokens,
        public int $latencyMs,
        public string $model,
        public int $thinkingTokens = 0,
        public int $cachedTokens = 0,
        public AiFinishReason $finishReason = AiFinishReason::Stop,
        /** Set only by the usage meter. */
        public ?float $costUsd = null,
        /** Cost the provider itself reported; null for drivers that do not. */
        public ?float $reportedCostUsd = null,
    ) {}

    public function usage(): AiUsage
    {
        return new AiUsage($this->inputTokens, $this->outputTokens, $this->thinkingTokens, $this->cachedTokens, $this->reportedCostUsd);
    }

    public function withCost(?float $costUsd): self
    {
        return new self(
            $this->text, $this->json, $this->inputTokens, $this->outputTokens, $this->latencyMs, $this->model,
            $this->thinkingTokens, $this->cachedTokens, $this->finishReason, $costUsd, $this->reportedCostUsd,
        );
    }
}
