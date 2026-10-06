<?php

namespace Korbytes\AiGateway\Crypto;

use Korbytes\AiGateway\Crypto\Exceptions\AiKeyException;

interface AiKeyProvider
{
    /** Key used to encrypt new provider credentials. */
    public function current(): AiKey;

    /**
     * Key by stored id (current or retired).
     *
     * @throws AiKeyException
     */
    public function find(string $id): AiKey;
}
