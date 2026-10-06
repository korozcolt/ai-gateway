<?php

namespace Korbytes\AiGateway\Crypto;

use Illuminate\Encryption\Encrypter;
use Korbytes\AiGateway\Crypto\Exceptions\AiKeyException;

/**
 * Reads the dedicated key that encrypts provider API keys stored in the DB (config `ai-gateway.credentials`).
 * It must differ from APP_KEY and from every key listed in `credentials.distinct_from`.
 * This is the only secret that lives outside the database: provider tokens themselves are stored encrypted in the DB.
 */
final class EnvironmentAiKeyProvider implements AiKeyProvider
{
    public const CIPHER = 'aes-256-gcm';

    public function current(): AiKey
    {
        $id = config('ai-gateway.credentials.current_key_id');

        if (! is_string($id) || $id === '') {
            throw new AiKeyException('The current AI credentials key id is not configured.');
        }

        return $this->find($id);
    }

    public function find(string $id): AiKey
    {
        $raw = config("ai-gateway.credentials.keys.{$id}") ?? config("ai-gateway.credentials.retired_keys.{$id}");

        if (! is_string($raw) || $raw === '') {
            throw new AiKeyException("AI credentials key [{$id}] is not available.");
        }

        return new AiKey($id, $this->parse($raw, $id));
    }

    private function parse(string $raw, string $id): string
    {
        $material = self::decode($raw);

        if ($material === null || ! Encrypter::supported($material, self::CIPHER)) {
            throw new AiKeyException("AI credentials key [{$id}] is malformed or has the wrong length.");
        }

        $appKey = self::decode((string) config('app.key')) ?? (string) config('app.key');

        if (hash_equals($appKey, $material)) {
            throw new AiKeyException("AI credentials key [{$id}] must differ from APP_KEY.");
        }

        foreach ((array) config('ai-gateway.credentials.distinct_from', []) as $otherKey) {
            $otherMaterial = is_string($otherKey) ? self::decode($otherKey) : null;

            if ($otherMaterial !== null && hash_equals($otherMaterial, $material)) {
                throw new AiKeyException("AI credentials key [{$id}] must differ from the other configured application keys.");
            }
        }

        return $material;
    }

    private static function decode(string $raw): ?string
    {
        if (! str_starts_with($raw, 'base64:')) {
            return null;
        }

        $material = base64_decode(substr($raw, strlen('base64:')), true);

        return $material === false ? null : $material;
    }
}
