<?php

namespace Korbytes\AiGateway\Dto;

final readonly class AiParams
{
    public function __construct(
        public string $model,
        public float $temperature,
        public int $maxOutputTokens,
        public int $timeoutSeconds,
    ) {}
}
