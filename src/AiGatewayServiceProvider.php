<?php

namespace Korbytes\AiGateway;

use Illuminate\Support\ServiceProvider;
use Korbytes\AiGateway\Console\GenerateKeyCommand;
use Korbytes\AiGateway\Console\ImportProvidersCommand;
use Korbytes\AiGateway\Crypto\AiKeyProvider;
use Korbytes\AiGateway\Crypto\EnvironmentAiKeyProvider;

class AiGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-gateway.php', 'ai-gateway');

        $this->app->singleton(AiKeyProvider::class, EnvironmentAiKeyProvider::class);
        $this->app->singleton(AiDriverRegistry::class);
        $this->app->singleton(AiProviderManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateKeyCommand::class, ImportProvidersCommand::class]);

            $this->publishes([__DIR__.'/../config/ai-gateway.php' => config_path('ai-gateway.php')], 'ai-gateway-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'ai-gateway-migrations');
        }
    }
}
