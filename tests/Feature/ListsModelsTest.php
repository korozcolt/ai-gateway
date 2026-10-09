<?php

use Illuminate\Support\Facades\Http;
use Korbytes\AiGateway\AiConnection;
use Korbytes\AiGateway\AiDriverRegistry;
use Korbytes\AiGateway\AiProviderManager;
use Korbytes\AiGateway\Contracts\ListsModels;
use Korbytes\AiGateway\Exceptions\AiProviderException;
use Korbytes\AiGateway\Facades\AiGateway;
use Korbytes\AiGateway\Models\AiProvider;
use Korbytes\AiGateway\Models\AiUsageEvent;
use Korbytes\AiGateway\Providers\FakeAiProvider;
use Korbytes\AiGateway\Providers\GeminiProvider;
use Korbytes\AiGateway\Providers\OpenAiCompatibleProvider;
use Korbytes\AiGateway\Usage\MeteredProvider;
use Korbytes\AiGateway\Usage\UsageRecorder;

beforeEach(fn () => Http::preventStrayRequests());

it('the registry decorator delegates listModels to a driver that supports it', function () {
    Http::fake(['*' => Http::response(['data' => [['id' => 'claude-x']], 'has_more' => false])]);
    $provider = AiProvider::factory()->create(['driver' => 'anthropic', 'base_url' => 'https://api.anthropic.com/v1', 'models' => [['id' => 'claude-x']]]);
    $provider->replaceApiKey('sk-test-listing-1');

    $driver = app(AiDriverRegistry::class)->make($provider->fresh());

    expect($driver)->toBeInstanceOf(MeteredProvider::class)->toBeInstanceOf(ListsModels::class)
        ->and($driver->listModels())->toBe(['claude-x']);
});

it('throws invalid_request when the inner driver cannot list models', function () {
    $metered = new MeteredProvider(new FakeAiProvider(new AiConnection('AIP-0001', 'fake', 'https://fake.invalid', null, true)), 1, fn () => null, app(UsageRecorder::class));

    expect(fn () => $metered->listModels())->toThrow(AiProviderException::class, 'invalid_request');
});

it('does not list Gemini or openai_compatible models in this version', function () {
    expect(GeminiProvider::class)->not->toImplement(ListsModels::class)
        ->and(OpenAiCompatibleProvider::class)->not->toImplement(ListsModels::class);
});

it('the manager reports and runs listing by connection code without writing to the usage ledger', function () {
    Http::fake(['*' => Http::response(['data' => [['id' => 'claude-y']], 'has_more' => false])]);
    $claude = AiProvider::factory()->create(['driver' => 'anthropic', 'base_url' => 'https://api.anthropic.com/v1', 'models' => [['id' => 'claude-y']]]);
    $claude->replaceApiKey('sk-test-listing-2');
    $gemini = AiProvider::factory()->gemini()->create();

    $manager = app(AiProviderManager::class);

    expect($manager->canListModels($claude->code))->toBeTrue()
        ->and($manager->canListModels($gemini->code))->toBeFalse()
        ->and($manager->canListModels('AIP-9999'))->toBeFalse()
        ->and(AiGateway::listModels($claude->code))->toBe(['claude-y'])
        ->and(AiUsageEvent::query()->count())->toBe(0);
    expect(fn () => $manager->listModels($gemini->code))->toThrow(AiProviderException::class);
});
