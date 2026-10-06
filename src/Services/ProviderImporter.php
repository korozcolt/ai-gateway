<?php

namespace Korbytes\AiGateway\Services;

use InvalidArgumentException;
use Korbytes\AiGateway\AiDriverRegistry;
use Korbytes\AiGateway\Models\AiProvider;
use Korbytes\AiGateway\RequestOptions;

/**
 * Creates/updates provider connections from a JSON file (seed). Tokens in the file are encrypted
 * before they reach the DB; the file must stay out of git. Idempotent: matched by (driver, name).
 *
 * File format: {"providers":[{"name","driver","base_url","api_key","supports_json_schema","is_active",
 * "request_options":{...},"models":[{"id","input_price_usd_per_million","output_price_usd_per_million"}]}]}
 */
final class ProviderImporter
{
    public function __construct(private readonly AiDriverRegistry $registry) {}

    /**
     * @return array{created: int, updated: int, keys_set: int}
     */
    public function importFile(string $path, bool $rotateKeys = false): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Seed file not found: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ! is_array($data['providers'] ?? null)) {
            throw new InvalidArgumentException('The seed file must be JSON with a "providers" array.');
        }

        return $this->import($data['providers'], $rotateKeys);
    }

    /**
     * @param  list<array<string, mixed>>  $providers
     * @return array{created: int, updated: int, keys_set: int}
     */
    public function import(array $providers, bool $rotateKeys = false): array
    {
        $result = ['created' => 0, 'updated' => 0, 'keys_set' => 0];

        foreach ($providers as $entry) {
            $this->validate($entry);

            $provider = AiProvider::query()->where('driver', $entry['driver'])->where('name', $entry['name'])->first();
            $attributes = [
                'driver' => $entry['driver'],
                'name' => $entry['name'],
                'base_url' => $entry['base_url'],
                'supports_json_schema' => (bool) ($entry['supports_json_schema'] ?? false),
                'models' => array_values($entry['models'] ?? []),
                'request_options' => RequestOptions::sanitize((array) ($entry['request_options'] ?? [])) ?: null,
                'budget_usd' => $entry['budget_usd'] ?? null,
                'is_active' => (bool) ($entry['is_active'] ?? false),
                'verified_at' => $entry['verified_at'] ?? null,
                'source_url' => $entry['source_url'] ?? null,
            ];

            $isNew = $provider === null;
            $provider = $provider ?? new AiProvider;
            $provider->fill($attributes)->save();
            $isNew ? $result['created']++ : $result['updated']++;

            $key = $entry['api_key'] ?? null;

            if (is_string($key) && $key !== '' && ($isNew || $rotateKeys || ! $provider->hasApiKey())) {
                $provider->replaceApiKey($key);
                $result['keys_set']++;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $entry */
    private function validate(array $entry): void
    {
        foreach (['name', 'driver', 'base_url'] as $required) {
            if (! isset($entry[$required]) || ! is_string($entry[$required]) || $entry[$required] === '') {
                throw new InvalidArgumentException("Provider entry is missing [{$required}].");
            }
        }

        if (! in_array($entry['driver'], $this->registry->keys(), true)) {
            throw new InvalidArgumentException("Unknown driver [{$entry['driver']}] for provider [{$entry['name']}].");
        }

        if (! str_starts_with($entry['base_url'], 'https://')) {
            throw new InvalidArgumentException("Provider [{$entry['name']}] base_url must start with https://.");
        }

        $denied = RequestOptions::deniedKeys((array) ($entry['request_options'] ?? []));

        if ($denied !== []) {
            throw new InvalidArgumentException("Provider [{$entry['name']}] request_options may not set: ".implode(', ', $denied).'.');
        }
    }
}
