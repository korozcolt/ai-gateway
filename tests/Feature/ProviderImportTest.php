<?php

use Illuminate\Support\Facades\DB;
use Korbytes\AiGateway\AiDriverRegistry;
use Korbytes\AiGateway\AiFailure;
use Korbytes\AiGateway\AiProviderManager;
use Korbytes\AiGateway\Database\Seeders\AiProvidersSeeder;
use Korbytes\AiGateway\Exceptions\AiProviderException;
use Korbytes\AiGateway\Facades\AiGateway;
use Korbytes\AiGateway\Models\AiProvider;
use Korbytes\AiGateway\Providers\FakeAiProvider;
use Korbytes\AiGateway\Services\ProviderImporter;

const IMPORT_TOKEN = 'sk-or-v1-distinctive-import-token-42';

function seedFile(array $providers): string
{
    $path = tempnam(sys_get_temp_dir(), 'ai-seed-');
    file_put_contents($path, json_encode(['providers' => $providers]));

    return $path;
}

function openRouterEntry(array $override = []): array
{
    return $override + [
        'name' => 'OpenRouter',
        'driver' => 'openai_compatible',
        'base_url' => 'https://openrouter.ai/api/v1',
        'api_key' => IMPORT_TOKEN,
        'supports_json_schema' => true,
        'is_active' => true,
        'request_options' => ['provider' => ['data_collection' => 'deny', 'zdr' => true]],
        'models' => [['id' => 'vendor/model', 'input_price_usd_per_million' => 1.5, 'output_price_usd_per_million' => 6]],
    ];
}

it('imports connections from a file and stores the token encrypted, never in clear text', function () {
    $result = app(ProviderImporter::class)->importFile(seedFile([openRouterEntry()]));

    $provider = AiProvider::query()->where('name', 'OpenRouter')->firstOrFail();
    $stored = DB::table('ai_providers')->where('id', $provider->id)->value('api_key');

    expect($result)->toBe(['created' => 1, 'updated' => 0, 'keys_set' => 1])
        ->and($stored)->not->toContain(IMPORT_TOKEN)
        ->and($provider->apiKey())->toBe(IMPORT_TOKEN)
        ->and($provider->request_options)->toEqual(['provider' => ['data_collection' => 'deny', 'zdr' => true]])
        ->and($provider->modelIds())->toBe(['vendor/model'])
        ->and($provider->is_active)->toBeTrue();
});

it('is idempotent and keeps the stored token unless rotation is requested', function () {
    $importer = app(ProviderImporter::class);
    $importer->import([openRouterEntry()]);
    $before = DB::table('ai_providers')->value('api_key');

    $again = $importer->import([openRouterEntry(['api_key' => 'sk-different', 'models' => []])]);

    expect($again)->toBe(['created' => 0, 'updated' => 1, 'keys_set' => 0])
        ->and(AiProvider::query()->count())->toBe(1)
        ->and(DB::table('ai_providers')->value('api_key'))->toBe($before)
        ->and(AiProvider::query()->firstOrFail()->modelIds())->toBe([]);

    $rotated = $importer->import([openRouterEntry(['api_key' => 'sk-rotated'])], rotateKeys: true);

    expect($rotated['keys_set'])->toBe(1)->and(AiProvider::query()->firstOrFail()->apiKey())->toBe('sk-rotated');
});

it('rejects invalid entries: unknown driver, non-https url, forbidden request options', function (array $override, string $message) {
    expect(fn () => app(ProviderImporter::class)->import([openRouterEntry($override)]))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown driver' => [['driver' => 'nope'], 'Unknown driver'],
    'http url' => [['base_url' => 'http://insecure.example'], 'https://'],
    'forbidden options' => [['request_options' => ['model' => 'x', 'headers' => ['a' => 'b']]], 'request_options may not set'],
    'missing name' => [['name' => ''], 'missing [name]'],
]);

it('imports through the artisan command and reports failures without leaking tokens', function () {
    $file = seedFile([openRouterEntry()]);

    $this->artisan('ai:providers:import', ['file' => $file])->expectsOutputToContain('1 created')->assertSuccessful();
    $this->artisan('ai:providers:import', ['file' => '/nonexistent.json'])->assertFailed();
});

it('the seeder always creates the offline fake connection and imports the seed file when present', function () {
    config(['ai-gateway.seed_file' => seedFile([openRouterEntry()])]);

    $this->seed(AiProvidersSeeder::class);

    expect(AiProvider::query()->where('driver', 'fake')->exists())->toBeTrue()
        ->and(AiProvider::query()->where('name', 'OpenRouter')->exists())->toBeTrue();

    config(['ai-gateway.seed_file' => '/nonexistent.json']);
    $this->seed(AiProvidersSeeder::class);

    expect(AiProvider::query()->where('driver', 'fake')->count())->toBe(1);
});

it('the manager resolves active connections by code and builds a metered driver', function () {
    $active = AiProvider::factory()->create();
    $inactive = AiProvider::factory()->inactive()->create();
    $manager = app(AiProviderManager::class);

    expect($manager->available())->toContain($active->fresh()->code)->not->toContain($inactive->fresh()->code)
        ->and($manager->modelsFor($active->fresh()->code))->toBe($active->modelIds())
        ->and($manager->provider($active->fresh()->code)->key())->toBe('fake');

    expect(fn () => $manager->provider('AIP-9999'))->toThrow(AiProviderException::class)
        ->and(fn () => $manager->provider($inactive->fresh()->code))->toThrow(AiProviderException::class);
});

it('requires credentials for real drivers but not for the fake one', function () {
    $noKey = AiProvider::factory()->nvidia()->create();

    expect(fn () => app(AiDriverRegistry::class)->make($noKey))->toThrow(AiProviderException::class);

    try {
        app(AiDriverRegistry::class)->make($noKey);
    } catch (AiProviderException $e) {
        expect($e->reasonCode)->toBe(AiFailure::MissingCredentials->value);
    }

    FakeAiProvider::reset();
    expect(app(AiDriverRegistry::class)->make(AiProvider::factory()->create())->key())->toBe('fake');
});

it('exposes the manager through the AiGateway facade', function () {
    $provider = AiProvider::factory()->create();

    expect(AiGateway::available())->toContain($provider->fresh()->code)
        ->and(AiGateway::provider($provider->fresh()->code)->key())->toBe('fake');
});
