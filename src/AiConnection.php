<?php

namespace Korbytes\AiGateway;

/** What a driver receives. The decrypted key lives only in this object, only in memory. */
final readonly class AiConnection
{
    public function __construct(
        public string $code,
        public string $driver,
        public string $baseUrl,
        #[\SensitiveParameter] public ?string $apiKey,
        public bool $supportsJsonSchema,
        /** @var array<string, mixed> extra request body fields (never secrets); sanitized by the driver */
        public array $requestOptions = [],
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'code' => $this->code,
            'driver' => $this->driver,
            'baseUrl' => $this->baseUrl,
            'supportsJsonSchema' => $this->supportsJsonSchema,
        ];
    }
}
