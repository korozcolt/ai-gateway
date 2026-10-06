<?php

namespace Korbytes\AiGateway\Tests;

use Illuminate\Encryption\Encrypter;
use Korbytes\AiGateway\AiGatewayServiceProvider;
use Korbytes\AiGateway\Crypto\EnvironmentAiKeyProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AiGatewayServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(Encrypter::generateKey('aes-256-cbc')));
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('ai-gateway.credentials.current_key_id', 'test-key-1');
        $app['config']->set('ai-gateway.credentials.keys', [
            'test-key-1' => 'base64:'.base64_encode(Encrypter::generateKey(EnvironmentAiKeyProvider::CIPHER)),
        ]);
    }
}
