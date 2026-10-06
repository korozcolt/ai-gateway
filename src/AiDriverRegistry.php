<?php

namespace Korbytes\AiGateway;

use Illuminate\Contracts\Encryption\DecryptException;
use Korbytes\AiGateway\Contracts\AiProviderInterface;
use Korbytes\AiGateway\Crypto\Exceptions\AiKeyException;
use Korbytes\AiGateway\Exceptions\AiProviderException;
use Korbytes\AiGateway\Models\AiProvider;
use Korbytes\AiGateway\Usage\MeteredProvider;
use Korbytes\AiGateway\Usage\UsageRecorder;

/**
 * The only place a connection becomes a driver. Every driver leaves here wrapped by the usage
 * meter (decorate), so no caller or driver can skip metering.
 */
final class AiDriverRegistry
{
    /** @return list<string> closed driver keys from config('ai-gateway.drivers') */
    public function keys(): array
    {
        return array_keys((array) config('ai-gateway.drivers', []));
    }

    /** @throws AiProviderException unknown_driver | missing_credentials | credentials_unavailable */
    public function make(AiProvider $provider): AiProviderInterface
    {
        $class = config("ai-gateway.drivers.{$provider->driver}");

        if (! is_string($class) || ! class_exists($class)) {
            throw new AiProviderException(AiFailure::UnknownDriver);
        }

        try {
            $apiKey = $provider->apiKey();
        } catch (AiKeyException|DecryptException $e) {
            // Key not configured, rotated without keeping the retired key, or corrupt ciphertext.
            throw new AiProviderException(AiFailure::CredentialsUnavailable, $e);
        }

        if ($provider->driver !== 'fake' && blank($apiKey)) {
            throw new AiProviderException(AiFailure::MissingCredentials);
        }

        $connection = new AiConnection(
            code: $provider->code,
            driver: $provider->driver,
            baseUrl: $provider->base_url,
            apiKey: $apiKey,
            supportsJsonSchema: (bool) $provider->supports_json_schema,
            requestOptions: RequestOptions::sanitize((array) ($provider->request_options ?? [])),
            modelOptions: $this->modelOptions($provider),
        );

        return $this->decorate(app()->make($class, ['connection' => $connection]), $provider);
    }

    /** Wraps the raw driver with the usage meter: the only way out of the registry. */
    protected function decorate(AiProviderInterface $driver, AiProvider $provider): AiProviderInterface
    {
        return new MeteredProvider(
            $driver,
            $provider->id,
            fn (string $model): ?ModelPrice => $provider->priceFor($model),
            app(UsageRecorder::class),
            $provider->modelIds(),
        );
    }

    /**
     * Per-model driver options declared in the connection's models list (max_tokens_param, temperature).
     *
     * @return array<string, array<string, mixed>>
     */
    private function modelOptions(AiProvider $provider): array
    {
        $options = [];

        foreach ($provider->models ?? [] as $model) {
            if (isset($model['id'])) {
                $options[(string) $model['id']] = array_intersect_key($model, array_flip(['max_tokens_param', 'temperature']));
            }
        }

        return $options;
    }
}
