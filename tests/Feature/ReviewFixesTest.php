<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Korbytes\AiGateway\AiConnection;
use Korbytes\AiGateway\AiDriverRegistry;
use Korbytes\AiGateway\AiFailure;
use Korbytes\AiGateway\Dto\AiMessage;
use Korbytes\AiGateway\Dto\AiParams;
use Korbytes\AiGateway\Dto\AiRequest;
use Korbytes\AiGateway\Exceptions\AiProviderException;
use Korbytes\AiGateway\Models\AiProvider;
use Korbytes\AiGateway\Models\AiUsageEvent;
use Korbytes\AiGateway\Providers\FakeAiProvider;
use Korbytes\AiGateway\Providers\GeminiProvider;
use Korbytes\AiGateway\Providers\OpenAiCompatibleProvider;
use Korbytes\AiGateway\Services\ProviderImporter;
use Korbytes\AiGateway\Usage\RecordAiUsageEvent;

function fixRequest(string $model = 'm1', string $purpose = 'general', ?string $contextType = null): AiRequest
{
    return new AiRequest('system', [new AiMessage('user', 'hi')], new AiParams($model, 0.7, 321, 5), purpose: $purpose, contextType: $contextType, contextId: $contextType ? 1 : null);
}

function okBody(): array
{
    return ['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]];
}

beforeEach(fn () => Http::preventStrayRequests());

it('adapts the request for reasoning models: max_completion_tokens and no temperature', function () {
    Http::fake(['*' => Http::response(okBody())]);
    $connection = new AiConnection('AIP-0001', 'openai_compatible', 'https://api.openai.com/v1', 'k', false, [], [
        'o-model' => ['max_tokens_param' => 'max_completion_tokens', 'temperature' => false],
    ]);

    (new OpenAiCompatibleProvider($connection))->complete(fixRequest('o-model'));
    (new OpenAiCompatibleProvider($connection))->complete(fixRequest('plain-model'));

    $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data());
    expect($sent[0])->toHaveKey('max_completion_tokens', 321)->not->toHaveKey('max_tokens')->not->toHaveKey('temperature')
        ->and($sent[1])->toHaveKey('max_tokens', 321)->toHaveKey('temperature', 0.7);
});

it('reads the per-model options from the connection models list through the registry', function () {
    Http::fake(['*' => Http::response(okBody())]);
    $provider = AiProvider::factory()->nvidia()->withKey('k')->create([
        'models' => [['id' => 'o-model', 'max_tokens_param' => 'max_completion_tokens', 'temperature' => false, 'input_price_usd_per_million' => 1, 'output_price_usd_per_million' => 2]],
    ]);

    app(AiDriverRegistry::class)->make($provider)->complete(fixRequest('o-model'));

    Http::assertSent(fn (Request $r) => isset($r->data()['max_completion_tokens']) && ! isset($r->data()['temperature']));
});

it('app fields win over connection options on a key collision', function () {
    Http::fake(['*' => Http::response(okBody())]);
    $connection = new AiConnection('AIP-0001', 'openai_compatible', 'https://x.example/v1', 'k', false, ['provider' => ['zdr' => true], 'extra' => 1]);

    (new OpenAiCompatibleProvider($connection))->complete(fixRequest('m1'));

    Http::assertSent(fn (Request $r) => $r->data()['model'] === 'm1' && $r->data()['extra'] === 1 && $r->data()['provider'] === ['zdr' => true]);
});

it('rejects a model the connection does not declare, before any HTTP call, and records it in the ledger', function () {
    $provider = AiProvider::factory()->nvidia()->withKey('k')->create();

    expect(fn () => app(AiDriverRegistry::class)->make($provider)->complete(fixRequest('not-declared')))
        ->toThrow(AiProviderException::class);

    Http::assertNothingSent();
    expect(AiUsageEvent::query()->firstOrFail()->outcome)->toBe(AiFailure::UnknownModel->value);
});

it('turns key errors into a closed AiProviderException instead of leaking AiKeyException', function () {
    $provider = AiProvider::factory()->nvidia()->withKey('k')->create();
    config(['ai-gateway.credentials.current_key_id' => 'test-key-1', 'ai-gateway.credentials.keys' => []]);

    try {
        app(AiDriverRegistry::class)->make($provider->fresh());
        $reason = null;
    } catch (AiProviderException $e) {
        $reason = $e->reasonCode;
    }

    expect($reason)->toBe(AiFailure::CredentialsUnavailable->value);
});

it('records a ledger row and rethrows the original when the inner driver fails with a non-gateway exception', function () {
    $provider = AiProvider::factory()->create(['models' => [['id' => 'm1', 'input_price_usd_per_million' => 1, 'output_price_usd_per_million' => 1]]]);
    FakeAiProvider::onComplete(fn () => throw new TypeError('boom'));

    expect(fn () => app(AiDriverRegistry::class)->make($provider)->complete(fixRequest('m1')))->toThrow(TypeError::class);

    expect(AiUsageEvent::query()->firstOrFail()->outcome)->toBe('internal_error');
    FakeAiProvider::reset();
});

it('truncates over-long app labels to the ledger column widths so the insert cannot fail', function () {
    $provider = AiProvider::factory()->create(['models' => [['id' => 'm1', 'input_price_usd_per_million' => 1, 'output_price_usd_per_million' => 1]]]);

    app(AiDriverRegistry::class)->make($provider)->complete(fixRequest('m1', str_repeat('p', 90), str_repeat('c', 100)));

    $event = AiUsageEvent::query()->firstOrFail();
    expect(mb_strlen($event->purpose))->toBe(40)->and(mb_strlen($event->context_type))->toBe(60);
});

it('the ledger job does not wait for the caller transaction to commit', function () {
    expect((new RecordAiUsageEvent([]))->afterCommit)->toBeFalse();
});

it('sends Gemini connection options but never lets them override the app fields', function () {
    Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']], 'usageMetadata' => []])]);
    $connection = new AiConnection('AIP-0002', 'gemini', 'https://generativelanguage.googleapis.com/v1beta', 'k', true, [
        'safetySettings' => [['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH']],
        'generationConfig' => ['temperature' => 2],
        'contents' => [],
    ]);

    (new GeminiProvider($connection))->complete(fixRequest('g'));

    Http::assertSent(fn (Request $r) => $r->data()['safetySettings'][0]['threshold'] === 'BLOCK_ONLY_HIGH'
        && $r->data()['generationConfig']['temperature'] === 0.7
        && $r->data()['contents'][0]['role'] === 'user');
});

it('classifies the Gemini invalid-key answer (400 API_KEY_INVALID) as auth', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'details' => [['reason' => 'API_KEY_INVALID']]]], 400)]);
    $connection = new AiConnection('AIP-0002', 'gemini', 'https://generativelanguage.googleapis.com/v1beta', 'k', true);

    try {
        (new GeminiProvider($connection))->complete(fixRequest('g'));
        $reason = null;
    } catch (AiProviderException $e) {
        $reason = $e->reasonCode;
    }

    expect($reason)->toBe(AiFailure::Auth->value);
});

it('re-importing keeps what an admin changed and the entry omits', function () {
    $importer = app(ProviderImporter::class);
    $entry = ['name' => 'OR', 'driver' => 'openai_compatible', 'base_url' => 'https://openrouter.ai/api/v1', 'is_active' => false, 'budget_usd' => 5, 'request_options' => ['provider' => ['zdr' => true]]];
    $importer->import([$entry]);
    AiProvider::query()->where('name', 'OR')->firstOrFail()->update(['is_active' => true, 'budget_usd' => 50]);

    $importer->import([['name' => 'OR', 'driver' => 'openai_compatible', 'base_url' => 'https://openrouter.ai/api/v1']]);

    $provider = AiProvider::query()->where('name', 'OR')->firstOrFail();
    expect($provider->is_active)->toBeTrue()
        ->and((float) $provider->budget_usd)->toBe(50.0)
        ->and($provider->request_options)->toEqual(['provider' => ['zdr' => true]]);
});

it('the import is all or nothing', function () {
    $good = ['name' => 'Good', 'driver' => 'openai_compatible', 'base_url' => 'https://good.example/v1'];
    $bad = ['name' => 'Bad', 'driver' => 'nope', 'base_url' => 'https://bad.example/v1'];

    expect(fn () => app(ProviderImporter::class)->import([$good, $bad]))->toThrow(InvalidArgumentException::class);

    expect(DB::table('ai_providers')->count())->toBe(0);
});
