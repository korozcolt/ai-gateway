<?php

namespace Korbytes\AiGateway;

use Korbytes\AiGateway\Dto\AiUsage;

/** Per-model price snapshot of a connection. Owns the only cost formula. */
final readonly class ModelPrice
{
    /** Unit constant: prices are expressed per million tokens. */
    private const TOKENS_PER_UNIT = 1_000_000;

    public function __construct(
        public float $inputUsdPerMillion,
        public float $outputUsdPerMillion,
        public ?string $verifiedAt = null,
        public ?string $sourceUrl = null,
    ) {}

    /**
     * Thinking tokens bill as output. Cached tokens are recorded but costed at the full input
     * price (conservative choice: the connection stores a single input price).
     */
    public function cost(AiUsage $usage): float
    {
        return (
            $usage->inputTokens * $this->inputUsdPerMillion
            + ($usage->outputTokens + $usage->thinkingTokens) * $this->outputUsdPerMillion
        ) / self::TOKENS_PER_UNIT;
    }
}
