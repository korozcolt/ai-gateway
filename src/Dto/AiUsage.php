<?php

namespace Korbytes\AiGateway\Dto;

/** Token counts a provider reported for one call (on success or on a failure that still bills). */
final readonly class AiUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $thinkingTokens = 0,
        public int $cachedTokens = 0,
        /** Cost in USD the provider reported for this call (for example OpenRouter usage.cost); null when it does not. */
        public ?float $reportedCostUsd = null,
    ) {}
}
