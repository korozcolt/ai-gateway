<?php

namespace Korbytes\AiGateway\Dto;

final readonly class AiRequest
{
    /**
     * @param  list<AiMessage>  $messages
     * @param  array<string, mixed>|null  $jsonSchema
     */
    public function __construct(
        public string $system,
        public array $messages,
        public AiParams $params,
        public ?array $jsonSchema = null,
        /** Free-form label chosen by the consuming app (e.g. classification, extraction, drafting, evaluation). */
        public string $purpose = 'general',
        /** Optional attribution of the call to an app entity (e.g. a case or a tenant): stored on the usage ledger. */
        public ?string $contextType = null,
        public ?int $contextId = null,
    ) {}
}
