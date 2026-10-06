<?php

namespace Korbytes\AiGateway\Crypto;

final readonly class AiKey
{
    public function __construct(public string $id, #[\SensitiveParameter] public string $material) {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['id' => $this->id];
    }
}
