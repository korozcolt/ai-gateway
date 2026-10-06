<?php

namespace Korbytes\AiGateway\Facades;

use Illuminate\Support\Facades\Facade;
use Korbytes\AiGateway\AiProviderManager;

/**
 * @method static list<string> available()
 * @method static list<string> modelsFor(string $code)
 * @method static \Korbytes\AiGateway\Models\AiProvider connection(string $code)
 * @method static \Korbytes\AiGateway\Contracts\AiProviderInterface provider(string $code)
 *
 * @see AiProviderManager
 */
class AiGateway extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AiProviderManager::class;
    }
}
