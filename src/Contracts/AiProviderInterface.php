<?php

namespace Korbytes\AiGateway\Contracts;

use Korbytes\AiGateway\Dto\AiRequest;
use Korbytes\AiGateway\Dto\AiResponse;
use Korbytes\AiGateway\Exceptions\AiProviderException;

interface AiProviderInterface
{
    public function key(): string;

    /** @throws AiProviderException */
    public function complete(AiRequest $request): AiResponse;
}
