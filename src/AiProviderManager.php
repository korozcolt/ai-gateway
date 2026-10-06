<?php

namespace Korbytes\AiGateway;

use Korbytes\AiGateway\Contracts\AiProviderInterface;
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
}
