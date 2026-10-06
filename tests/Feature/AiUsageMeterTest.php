<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Korbytes\AiGateway\AiDriverRegistry;
use Korbytes\AiGateway\Dto\AiMessage;
use Korbytes\AiGateway\Dto\AiParams;
use Korbytes\AiGateway\Dto\AiRequest;
use Korbytes\AiGateway\Dto\AiResponse;
use Korbytes\AiGateway\Exceptions\AiProviderException;
use Korbytes\AiGateway\Models\AiProvider;
use Korbytes\AiGateway\Models\AiUsageEvent;
use Korbytes\AiGateway\Providers\FakeAiProvider;
use Korbytes\AiGateway\Usage\RecordAiUsageEvent;

const METER_PROMPT = 'METER-DISTINCTIVE-PROMPT-77';
const METER_REPLY = 'METER-DISTINCTIVE-REPLY-88';

function meterRequest(?string $model = null, string $purpose = 'general', ?int $contextId = null): AiRequest
{
    return new AiRequest(
        'system '.METER_PROMPT,
        [new AiMessage('user', METER_PROMPT)],
        new AiParams($model ?? 'gemini-test-model', 0.3, 100, 5),
        purpose: $purpose,
        contextType: $contextId === null ? null : 'case',
        contextId: $contextId,
    );
}

function meteredGemini(array $attributes = []): AiProvider
{
    return AiProvider::factory()->gemini()->withKey('meter-key')->create($attributes + [
        'models' => [['id' => 'gemini-test-model', 'input_price_usd_per_million' => 2.0, 'output_price_usd_per_million' => 8.0]],
    ]);
}

function geminiBody(array $usage = []): array
{
    return [
        'candidates' => [['content' => ['parts' => [['text' => METER_REPLY]]], 'finishReason' => 'STOP']],
        'usageMetadata' => $usage + ['promptTokenCount' => 1000, 'candidatesTokenCount' => 500, 'thoughtsTokenCount' => 200],
    ];
}

beforeEach(fn () => Http::preventStrayRequests());

it('meters one event per complete() for each driver', function () {
    $gemini = meteredGemini();
    $nvidia = AiProvider::factory()->nvidia()->withKey('k')->create();
    $fake = AiProvider::factory()->create();
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiBody()),
        'integrate.api.nvidia.com/*' => Http::response(['choices' => [['message' => ['content' => 'x'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]]),
    ]);
    $registry = app(AiDriverRegistry::class);

    $registry->make($gemini)->complete(meterRequest());
    $registry->make($nvidia)->complete(meterRequest('nvidia/test-model'));
    $registry->make($fake)->complete(meterRequest($fake->modelIds()[0]));

    expect(AiUsageEvent::query()->count())->toBe(3)
        ->and(AiUsageEvent::query()->pluck('ai_provider_id')->sort()->values()->all())
        ->toBe(collect([$gemini->id, $nvidia->id, $fake->id])->sort()->values()->all());
});

it('computes cost at call time from the price snapshot and keeps old rows when the price changes', function () {
    $provider = meteredGemini();
    Http::fake(['*' => Http::response(geminiBody())]);

    $response = app(AiDriverRegistry::class)->make($provider)->complete(meterRequest());

    // 1000 in x 2.00 + (500 out + 200 thinking) x 8.00 = 2000 + 5600 per million = 0.0076 USD
    expect($response->costUsd)->toEqualWithDelta(0.0076, 1e-9);

    $event = AiUsageEvent::query()->firstOrFail();
    expect((float) $event->cost_usd)->toEqualWithDelta(0.0076, 1e-9)
        ->and((float) $event->price_input_usd_per_million)->toBe(2.0)
        ->and((float) $event->price_output_usd_per_million)->toBe(8.0)
        ->and($event->thinking_tokens)->toBe(200)
        ->and($event->outcome)->toBe('ok');

    $provider->update(['models' => [['id' => 'gemini-test-model', 'input_price_usd_per_million' => 99, 'output_price_usd_per_million' => 99]]]);

    expect((float) $event->fresh()->cost_usd)->toEqualWithDelta(0.0076, 1e-9)
        ->and((float) $event->fresh()->price_input_usd_per_million)->toBe(2.0);
});

it('stores a null cost and null snapshot when the model has no price', function () {
    $provider = meteredGemini(['models' => [['id' => 'gemini-test-model', 'input_price_usd_per_million' => null, 'output_price_usd_per_million' => null]]]);
    Http::fake(['*' => Http::response(geminiBody())]);

    $response = app(AiDriverRegistry::class)->make($provider)->complete(meterRequest());

    $event = AiUsageEvent::query()->firstOrFail();
    expect($response->costUsd)->toBeNull()
        ->and($event->cost_usd)->toBeNull()
        ->and($event->price_input_usd_per_million)->toBeNull();
});

it('meters failures with the outcome code, zero tokens, and rethrows the same exception', function (int $status, string $outcome) {
    $provider = meteredGemini();
    Http::fake($status === 0 ? fn () => throw new ConnectionException('cURL error 6') : ['*' => Http::response([], $status)]);
    $driver = app(AiDriverRegistry::class)->make($provider);

    $thrown = null;
    try {
        $driver->complete(meterRequest());
    } catch (AiProviderException $e) {
        $thrown = $e;
    }

    $event = AiUsageEvent::query()->firstOrFail();
    expect($thrown)->not->toBeNull()
        ->and($thrown->reasonCode)->toBe($outcome)
        ->and($event->outcome)->toBe($outcome)
        ->and($event->input_tokens)->toBe(0)
        ->and($event->output_tokens)->toBe(0)
        ->and((float) $event->cost_usd)->toBe(0.0);
})->with([
    'auth' => [401, 'auth'],
    'rate limit' => [429, 'rate_limit'],
    'server error' => [500, 'server_error'],
    'connection' => [0, 'connection'],
]);

it('records the real elapsed time of a failed call, not zero', function () {
    $provider = meteredGemini();
    Http::fake(fn () => usleep(60000) ?: Http::response([], 500));
    $driver = app(AiDriverRegistry::class)->make($provider);

    try {
        $driver->complete(meterRequest());
    } catch (AiProviderException) {
        // expected
    }

    expect(AiUsageEvent::query()->firstOrFail()->latency_ms)->toBeGreaterThanOrEqual(50);
});

it('records the tokens and cost of a content-filtered response that reports usage', function () {
    $provider = meteredGemini();
    Http::fake(['*' => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY'], 'usageMetadata' => ['promptTokenCount' => 1000]])]);

    expect(fn () => app(AiDriverRegistry::class)->make($provider)->complete(meterRequest()))
        ->toThrow(AiProviderException::class);

    $event = AiUsageEvent::query()->firstOrFail();
    expect($event->outcome)->toBe('content_filtered')
        ->and($event->input_tokens)->toBe(1000)
        ->and((float) $event->cost_usd)->toEqualWithDelta(0.002, 1e-9);
});

it('propagates purpose and context to the ledger', function () {
    $provider = meteredGemini();
    Http::fake(['*' => Http::response(geminiBody())]);
    $driver = app(AiDriverRegistry::class)->make($provider);

    $driver->complete(meterRequest(purpose: 'classification', contextId: 42));
    $driver->complete(meterRequest(purpose: 'extraction', contextId: 42));
    $driver->complete(meterRequest(purpose: 'evaluation'));

    $rows = AiUsageEvent::query()->orderBy('id')->get()->map(fn ($e) => [$e->purpose, $e->context_type, $e->context_id])->all();

    expect($rows)->toBe([
        ['classification', 'case', 42],
        ['extraction', 'case', 42],
        ['evaluation', null, null],
    ]);
});

it('swallows a recorder failure, reports it and still returns the response', function () {
    Exceptions::fake();
    $provider = meteredGemini();
    Http::fake(['*' => Http::response(geminiBody())]);
    config(['queue.default' => 'missing-connection']);

    $response = app(AiDriverRegistry::class)->make($provider)->complete(meterRequest());

    expect($response->text)->toBe(METER_REPLY)
        ->and(AiUsageEvent::query()->count())->toBe(0);
    Exceptions::assertReportedCount(1);
});

it('dispatches a job whose payload is scalar counters only', function () {
    Queue::fake();
    $provider = meteredGemini();
    Http::fake(['*' => Http::response(geminiBody())]);

    app(AiDriverRegistry::class)->make($provider)->complete(meterRequest());

    Queue::assertPushed(RecordAiUsageEvent::class, function (RecordAiUsageEvent $job) {
        $json = json_encode($job->event);

        return collect($job->event)->every(fn ($value) => $value === null || is_scalar($value))
            && ! str_contains($json, METER_PROMPT)
            && ! str_contains($json, METER_REPLY)
            && ! str_contains($json, 'meter-key')
            && array_keys($job->event) === [
                'ai_provider_id', 'context_type', 'context_id', 'model', 'purpose', 'input_tokens', 'output_tokens', 'thinking_tokens',
                'cached_tokens', 'price_input_usd_per_million', 'price_output_usd_per_million', 'cost_usd', 'reported_cost_usd',
                'latency_ms', 'outcome', 'occurred_at',
            ];
    });
});

it('keeps the ledger row when the caller transaction rolls back (queued job path)', function () {
    // Test setup: the sync queue would run the job inside the caller transaction, so the job is captured with
    // Queue::fake() and executed after the rollback, exactly what a real queue worker does.
    Queue::fake();
    $provider = meteredGemini();
    Http::fake(['*' => Http::response([], 500)]);
    $driver = app(AiDriverRegistry::class)->make($provider);

    try {
        DB::transaction(function () use ($driver) {
            try {
                $driver->complete(meterRequest());
            } catch (AiProviderException $e) {
                throw $e;
            }
        });
    } catch (AiProviderException) {
        // the caller's work is rolled back
    }

    Queue::assertPushed(RecordAiUsageEvent::class, 1);
    Queue::pushed(RecordAiUsageEvent::class)->each(fn (RecordAiUsageEvent $job) => $job->handle());

    expect(AiUsageEvent::query()->where('outcome', 'server_error')->count())->toBe(1);
});

it('stores no content: no text or json column, and prompt/response strings appear nowhere', function () {
    $provider = meteredGemini();
    Http::fake(['*' => Http::response(geminiBody())]);
    app(AiDriverRegistry::class)->make($provider)->complete(meterRequest());

    $textual = collect(Schema::getColumns('ai_usage_events'))
        ->filter(fn ($column) => str_contains($column['type_name'], 'text') || str_contains($column['type_name'], 'json'))
        ->pluck('name')->all();

    $dump = json_encode(DB::table('ai_usage_events')->get());

    expect($textual)->toBe([])
        ->and($dump)->not->toContain(METER_PROMPT)->not->toContain(METER_REPLY)->not->toContain('meter-key');
});

it('guards the ledger: no update, no delete outside the purge function', function () {
    $provider = meteredGemini();
    Http::fake(['*' => Http::response(geminiBody())]);
    app(AiDriverRegistry::class)->make($provider)->complete(meterRequest(contextId: 7));
    $event = AiUsageEvent::query()->firstOrFail();

    expect($event->code)->toMatch('/^AUE-\d{8}$/')
        ->and(dbRejects(fn () => DB::table('ai_usage_events')->where('id', $event->id)->update(['input_tokens' => 1])))->toBeTrue()
        ->and(dbRejects(fn () => DB::table('ai_usage_events')->where('id', $event->id)->update(['context_id' => null])))->toBeTrue()
        ->and(dbRejects(fn () => DB::table('ai_usage_events')->where('id', $event->id)->delete()))->toBeTrue();

    $deleted = DB::selectOne("select ai_usage_events_purge(now() + interval '1 day') as n")->n;

    expect((int) $deleted)->toBe(1)->and(AiUsageEvent::query()->count())->toBe(0);
});

it('rejects negative tokens at the database level', function () {
    $provider = meteredGemini();

    expect(dbRejects(fn () => AiUsageEvent::query()->create([
        'ai_provider_id' => $provider->id, 'model' => 'm', 'purpose' => 'general', 'outcome' => 'ok',
        'occurred_at' => now(), 'input_tokens' => -1,
    ])))->toBeTrue();
});

it('wraps the fake provider too, so no driver can skip the meter', function () {
    $fake = AiProvider::factory()->create(['models' => [['id' => 'fake-model', 'input_price_usd_per_million' => 0, 'output_price_usd_per_million' => 0]]]);
    FakeAiProvider::queue(new AiResponse('x', null, 3, 2, 0, 'fake-model'));

    app(AiDriverRegistry::class)->make($fake)->complete(meterRequest($fake->modelIds()[0]));

    $event = AiUsageEvent::query()->firstOrFail();
    expect($event->input_tokens)->toBe(3)->and((float) $event->cost_usd)->toBe(0.0);
});

it('has no unmetered construction path for the real drivers', function () {
    $hits = shell_exec('grep -rn "new GeminiProvider\|new OpenAiCompatibleProvider" '.escapeshellarg(dirname(__DIR__, 2).'/src'));

    expect(trim((string) $hits))->toBe('');
});
