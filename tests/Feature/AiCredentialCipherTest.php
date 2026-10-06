<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Korbytes\AiGateway\Crypto\AiCredentialCipher;
use Korbytes\AiGateway\Crypto\AiKeyId;
use Korbytes\AiGateway\Crypto\AiKeyProvider;
use Korbytes\AiGateway\Crypto\EnvironmentAiKeyProvider;
use Korbytes\AiGateway\Crypto\Exceptions\AiKeyException;

function newAiKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

function useAiKey(string $id, string $key, array $retired = []): void
{
    config([
        'ai-gateway.credentials.current_key_id' => $id,
        'ai-gateway.credentials.keys' => [$id => $key],
        'ai-gateway.credentials.retired_keys' => $retired,
    ]);
}

it('encrypts and decrypts with the dedicated key and records the key id', function () {
    $cipher = new AiCredentialCipher(new EnvironmentAiKeyProvider);

    $result = $cipher->encrypt('secret-value');

    expect($result['api_key'])->not->toBe('secret-value')
        ->and($result['api_key_key_id'])->toBe(config('ai-gateway.credentials.current_key_id'))
        ->and($cipher->decrypt($result['api_key'], $result['api_key_key_id']))->toBe('secret-value');
});

it('cannot be decrypted with APP_KEY', function () {
    $result = (new AiCredentialCipher(new EnvironmentAiKeyProvider))->encrypt('secret-value');

    Crypt::decryptString($result['api_key']);
})->throws(DecryptException::class);

it('fails closed on a missing, malformed, short, APP_KEY-equal or distinct_from-equal key', function (string $case) {
    $key = match ($case) {
        'missing' => null,
        'no prefix' => base64_encode(random_bytes(32)),
        'short' => 'base64:'.base64_encode(random_bytes(16)),
        'not base64' => 'base64:***not-base64***',
        'app key' => (string) config('app.key'),
        'distinct_from key' => 'distinct_from-placeholder',
    };
    config(['ai-gateway.credentials.distinct_from' => [$other = newAiKey()]]);
    $key = $key === 'distinct_from-placeholder' ? $other : $key;
    $key === null
        ? config(['ai-gateway.credentials.current_key_id' => AiKeyId::generate(), 'ai-gateway.credentials.keys' => []])
        : useAiKey('k1', $key);

    (new EnvironmentAiKeyProvider)->current();
})->with(['missing', 'no prefix', 'short', 'not base64', 'app key', 'distinct_from key'])->throws(AiKeyException::class);

it('never falls back to APP_KEY when the AI key is missing', function () {
    config(['ai-gateway.credentials.current_key_id' => null, 'ai-gateway.credentials.keys' => []]);

    expect(fn () => (new AiCredentialCipher(new EnvironmentAiKeyProvider))->encrypt('x'))->toThrow(AiKeyException::class);
});

it('keeps decrypting values from a retired key and stamps new values with the new key id', function () {
    $oldKey = newAiKey();
    useAiKey('k1', $oldKey);
    $cipher = new AiCredentialCipher(new EnvironmentAiKeyProvider);
    $old = $cipher->encrypt('old');

    useAiKey('k2', newAiKey(), ['k1' => $oldKey]);
    $new = $cipher->encrypt('new');

    expect($old['api_key_key_id'])->toBe('k1')
        ->and($new['api_key_key_id'])->toBe('k2')
        ->and($cipher->decrypt($old['api_key'], 'k1'))->toBe('old')
        ->and($cipher->decrypt($new['api_key'], 'k2'))->toBe('new');
});

it('throws on an unknown key id', function () {
    (new EnvironmentAiKeyProvider)->find('unknown');
})->throws(AiKeyException::class);

it('produces different ciphertexts for the same plaintext', function () {
    $cipher = new AiCredentialCipher(new EnvironmentAiKeyProvider);

    expect($cipher->encrypt('same')['api_key'])->not->toBe($cipher->encrypt('same')['api_key']);
});

it('resolves the environment provider and works end to end with the test key', function () {
    expect(app(AiKeyProvider::class))->toBeInstanceOf(EnvironmentAiKeyProvider::class);

    $cipher = app(AiCredentialCipher::class);
    $result = $cipher->encrypt('end to end');

    expect($cipher->decrypt($result['api_key'], $result['api_key_key_id']))->toBe('end to end')
        ->and(config('ai-gateway.credentials.keys.'.config('ai-gateway.credentials.current_key_id')))->not->toBe(config('app.key'));
});

it('generates a key with a system-generated ULID id', function () {
    Artisan::call('ai:generate-key');
    $first = Artisan::output();
    Artisan::call('ai:generate-key');
    $second = Artisan::output();

    expect($first)->toContain('AI_CREDENTIALS_KEY=base64:')
        ->and($first)->toMatch('/^AI_CREDENTIALS_KEY_ID=[0-9a-z]{26}$/m');

    preg_match('/^AI_CREDENTIALS_KEY_ID=(\S+)$/m', $first, $a);
    preg_match('/^AI_CREDENTIALS_KEY_ID=(\S+)$/m', $second, $b);

    expect($a[1])->not->toBe($b[1])
        ->and(Str::isUlid(AiKeyId::generate()))->toBeTrue();
});

it('reads env only inside config files', function () {
    $hits = shell_exec('grep -rn "env(" '.escapeshellarg(dirname(__DIR__, 2).'/src'));

    expect(trim((string) $hits))->toBe('');
});
