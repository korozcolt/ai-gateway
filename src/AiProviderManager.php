<?php

namespace Korbytes\AiGateway;

use Korbytes\AiGateway\Contracts\AiProviderInterface;
use Korbytes\AiGateway\Contracts\ListsModels;
use Korbytes\AiGateway\Exceptions\AiProviderException;
use Korbytes\AiGateway\Models\AiProvider;

/** Resolves connections by code and builds their (metered) drivers. */
final class AiProviderManager
{
    public function __construct(private readonly AiDriverRegistry $registry) {}

    /** @return list<string> codes of ACTIVE connections */
    public function available(): array
    {
        return AiProvider::query()->active()->orderBy('id')->pluck('code')->all();
    }

    /** @return list<string> model ids of a connection */
    public function modelsFor(string $code): array
    {
        return AiProvider::query()->where('code', $code)->first()?->modelIds() ?? [];
    }

    /** @throws AiProviderException unknown_provider | provider_inactive */
    public function connection(string $code): AiProvider
    {
        $provider = AiProvider::query()->where('code', $code)->first()
            ?? throw new AiProviderException(AiFailure::UnknownProvider);

        if (! $provider->is_active) {
            throw new AiProviderException(AiFailure::ProviderInactive);
        }

        return $provider;
    }

    public function provider(string $code): AiProviderInterface
    {
        return $this->registry->make($this->connection($code));
    }

    /** Whether the driver of the connection can list the remote models (ListsModels). */
    public function canListModels(string $code): bool
    {
        $driver = AiProvider::query()->where('code', $code)->value('driver');
        $class = is_string($driver) ? config("ai-gateway.drivers.{$driver}") : null;

        return is_string($class) && is_a($class, ListsModels::class, true);
    }

    /**
     * Model ids the remote account offers. The consumer decides which ones to store; listing is not metered.
     *
     * @return list<string>
     *
     * @throws AiProviderException
     */
    public function listModels(string $code): array
    {
        $provider = $this->provider($code);

        if (! $provider instanceof ListsModels) {
            throw new AiProviderException(AiFailure::InvalidRequest);
        }

        return $provider->listModels();
    }
}
