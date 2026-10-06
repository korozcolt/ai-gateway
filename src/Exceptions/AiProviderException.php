<?php

namespace Korbytes\AiGateway\Exceptions;

use Korbytes\AiGateway\AiFailure;
use Korbytes\AiGateway\Dto\AiUsage;
use RuntimeException;
use Throwable;

/**
 * Carries only a reason code (an AiFailure value): never prompt text, never the previous message.
 * `usage` holds the tokens the provider reported even when the call failed (blocked/filtered).
 */
class AiProviderException extends RuntimeException
{
    public readonly string $reasonCode;

    public function __construct(AiFailure|string $reasonCode, ?Throwable $previous = null, public readonly ?AiUsage $usage = null)
    {
        $this->reasonCode = $reasonCode instanceof AiFailure ? $reasonCode->value : $reasonCode;

        parent::__construct("AI provider failure: {$this->reasonCode}", 0, $previous);
    }
}
