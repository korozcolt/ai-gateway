<?php

namespace Korbytes\AiGateway\Contracts;

use Korbytes\AiGateway\Exceptions\AiProviderException;

/**
 * Optional capability of a driver: list the model ids the remote account can use.
 * It is not part of AiProviderInterface, so existing drivers are unaffected. The package never
 * persists what it lists: the consuming app decides which ids to keep.
 */
interface ListsModels
{
    /**
     * @return list<string> model ids
     *
     * @throws AiProviderException
     */
    public function listModels(): array;
}
