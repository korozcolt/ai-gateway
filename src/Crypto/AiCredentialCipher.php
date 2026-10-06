<?php

namespace Korbytes\AiGateway\Crypto;

use Illuminate\Encryption\Encrypter;

/**
 * Encrypts provider API keys with the dedicated AI key (distinct from APP_KEY and the chat key).
 * Never use the Crypt facade or model casts for these values: they rely on APP_KEY.
 */
final class AiCredentialCipher
{
    public function __construct(private readonly AiKeyProvider $keys) {}

    /** @return array{api_key: string, api_key_key_id: string} */
    public function encrypt(#[\SensitiveParameter] string $plain): array
    {
        $key = $this->keys->current();

        return [
            'api_key' => (new Encrypter($key->material, EnvironmentAiKeyProvider::CIPHER))->encryptString($plain),
            'api_key_key_id' => $key->id,
        ];
    }

    public function decrypt(string $cipher, string $keyId): string
    {
        $key = $this->keys->find($keyId);

        return (new Encrypter($key->material, EnvironmentAiKeyProvider::CIPHER))->decryptString($cipher);
    }
}
