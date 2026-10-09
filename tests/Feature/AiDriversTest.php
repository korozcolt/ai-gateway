<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Korbytes\AiGateway\AiConnection;
use Korbytes\AiGateway\AiDriverRegistry;
use Korbytes\AiGateway\AiFinishReason;
use Korbytes\AiGateway\Dto\AiMessage;
use Korbytes\AiGateway\Dto\AiParams;
use Korbytes\AiGateway\Dto\AiRequest;
use Korbytes\AiGateway\Exceptions\AiProviderException;
use Korbytes\AiGateway\Models\AiProvider;
use Korbytes\AiGateway\Models\AiUsageEvent;
use Korbytes\AiGateway\Providers\GeminiProvider;
use Korbytes\AiGateway\Providers\OpenAiCompatibleProvider;

const DRIVER_KEY = 'sk-driver-test-key-5d2e';
const DRIVER_PROMPT = 'DISTINCTIVE-PROMPT-TEXT-91';

function driverRequest(?array $schema = null): AiRequest
{
    return new AiRequest(
        'Be brief. '.DRIVER_PROMPT,
        [new AiMessage('user', 'hello'), new AiMessage('assistant', 'hi'), new AiMessage('user', 'again')],
        new AiParams('the-model', 0.4, 123, 7),
        $schema,
    );
}

function geminiProvider(): GeminiProvider
{
    return new GeminiProvider(new AiConnection('AIP-0002', 'gemini', 'https://generativelanguage.googleapis.com/v1beta', DRIVER_KEY, true));
}

function nvidiaProvider(bool $json = false): OpenAiCompatibleProvider
{
    return new OpenAiCompatibleProvider(new AiConnection('AIP-0003', 'openai_compatible', 'https://integrate.api.nvidia.com/v1', DRIVER_KEY, $json));
}

function geminiOk(array $usage = [], string $finish = 'STOP', string $text = 'Hola'): array
{
    return [
        'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $text]]], 'finishReason' => $finish]],
        'usageMetadata' => $usage + ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
    ];
}

beforeEach(fn () => Http::preventStrayRequests());

// ---- Gemini ----

it('sends the exact Gemini request: header key, system instruction, role mapping, no tools', function () {
    Http::fake(['*' => Http::response(geminiOk())]);

    geminiProvider()->complete(driverRequest(['type' => 'object']));

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/the-model:generateContent'
            && $request->hasHeader('x-goog-api-key', DRIVER_KEY)
            && ! str_contains($request->url(), DRIVER_KEY)
            && ! str_contains($request->url(), 'key=')
            && $body['systemInstruction']['parts'][0]['text'] === 'Be brief. '.DRIVER_PROMPT
            && array_column($body['contents'], 'role') === ['user', 'model', 'user']
            && $body['generationConfig']['temperature'] === 0.4
            && $body['generationConfig']['maxOutputTokens'] === 123
            && $body['generationConfig']['responseMimeType'] === 'application/json'
            && $body['generationConfig']['responseJsonSchema'] === ['type' => 'object']
            && ! array_key_exists('tools', $body)
            && ! array_key_exists('cachedContent', $body)
            && ! str_contains(json_encode($body), 'grounding');
    });
});

it('omits the schema keys when no schema is requested', function () {
    Http::fake(['*' => Http::response(geminiOk())]);

    geminiProvider()->complete(driverRequest());

    Http::assertSent(fn (Request $r) => ! isset($r->data()['generationConfig']['responseJsonSchema']));
});

it('parses Gemini usage with thinking and cached tokens kept separate', function () {
    Http::fake(['*' => Http::response(geminiOk(['thoughtsTokenCount' => 40, 'cachedContentTokenCount' => 3], 'MAX_TOKENS', "```json\n{\"a\":1}\n```"))]);

    $response = geminiProvider()->complete(driverRequest(['type' => 'object']));

    expect($response->inputTokens)->toBe(10)
        ->and($response->outputTokens)->toBe(5)
        ->and($response->thinkingTokens)->toBe(40)
        ->and($response->cachedTokens)->toBe(3)
        ->and($response->finishReason)->toBe(AiFinishReason::Length)
        ->and($response->json)->toBe(['a' => 1])
        ->and($response->model)->toBe('the-model');
});

it('maps Gemini HTTP statuses to the closed failure taxonomy', function (int $status, string $reason) {
    Http::fake(['*' => Http::response(['error' => ['code' => $status, 'message' => DRIVER_PROMPT]], $status)]);

    try {
        geminiProvider()->complete(driverRequest());
        $this->fail('expected exception');
    } catch (AiProviderException $e) {
        expect($e->reasonCode)->toBe($reason)
            ->and($e->getMessage())->not->toContain(DRIVER_PROMPT)
            ->and($e->getPrevious())->toBeNull();
    }
})->with([
    [400, 'invalid_request'], [404, 'invalid_request'], [401, 'auth'], [402, 'auth'], [403, 'auth'],
    [429, 'rate_limit'], [500, 'server_error'], [503, 'server_error'], [504, 'timeout'],
]);

it('maps a blocked Gemini prompt to content_filtered and keeps the reported usage', function () {
    Http::fake(['*' => Http::response([
        'promptFeedback' => ['blockReason' => 'SAFETY'],
        'usageMetadata' => ['promptTokenCount' => 12],
    ])]);

    try {
        geminiProvider()->complete(driverRequest());
        $this->fail('expected exception');
    } catch (AiProviderException $e) {
        expect($e->reasonCode)->toBe('content_filtered')->and($e->usage->inputTokens)->toBe(12);
    }
});

it('maps a filtered candidate without text to content_filtered', function () {
    Http::fake(['*' => Http::response(['candidates' => [['finishReason' => 'SAFETY']], 'usageMetadata' => ['promptTokenCount' => 4]])]);

    expect(fn () => geminiProvider()->complete(driverRequest()))->toThrow(AiProviderException::class, 'content_filtered');
});

// ---- OpenAI-compatible (NVIDIA Build) ----

it('sends the exact NVIDIA Build request: URL, Bearer header, system first, max_tokens', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Hola'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 4]])]);

    $response = nvidiaProvider()->complete(driverRequest());

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === 'https://integrate.api.nvidia.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer '.DRIVER_KEY)
            && $body['model'] === 'the-model'
            && $body['messages'][0] === ['role' => 'system', 'content' => 'Be brief. '.DRIVER_PROMPT]
            && array_column($body['messages'], 'role') === ['system', 'user', 'assistant', 'user']
            && $body['temperature'] === 0.4
            && $body['max_tokens'] === 123
            && ! array_key_exists('response_format', $body)
            && ! array_key_exists('tools', $body);
    });
    expect($response->text)->toBe('Hola')->and($response->inputTokens)->toBe(9)->and($response->outputTokens)->toBe(4);
});

it('uses response_format only when the connection supports json schema, else asks in the prompt', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"a":1}'], 'finish_reason' => 'stop']], 'usage' => []])]);

    $with = nvidiaProvider(true)->complete(driverRequest(['type' => 'object']));
    $without = nvidiaProvider(false)->complete(driverRequest(['type' => 'object']));

    expect($with->json)->toBe(['a' => 1])->and($without->json)->toBe(['a' => 1]);

    $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data())->all();
    expect($sent[0]['response_format']['type'])->toBe('json_schema')
        ->and($sent[0]['response_format']['json_schema']['schema'])->toBe(['type' => 'object'])
        ->and($sent[1])->not->toHaveKey('response_format')
        ->and($sent[1]['messages'][0]['content'])->toContain('JSON Schema');
});

it('splits reasoning and cached tokens out of OpenAI-style usage', function () {
    Http::fake(['*' => Http::response([
        'choices' => [['message' => ['content' => 'x'], 'finish_reason' => 'length']],
        'usage' => [
            'prompt_tokens' => 100, 'completion_tokens' => 60,
            'completion_tokens_details' => ['reasoning_tokens' => 45],
            'prompt_tokens_details' => ['cached_tokens' => 20],
        ],
    ])]);

    $response = nvidiaProvider()->complete(driverRequest());

    expect($response->outputTokens)->toBe(15)->and($response->thinkingTokens)->toBe(45)
        ->and($response->cachedTokens)->toBe(20)->and($response->inputTokens)->toBe(100)
        ->and($response->finishReason)->toBe(AiFinishReason::Length);
});

it('maps OpenAI-compatible HTTP statuses to the closed failure taxonomy', function (int $status, array $body, string $reason) {
    Http::fake(['*' => Http::response($body, $status)]);

    try {
        nvidiaProvider()->complete(driverRequest());
        $this->fail('expected exception');
    } catch (AiProviderException $e) {
        expect($e->reasonCode)->toBe($reason)->and($e->getPrevious())->toBeNull();
    }
})->with([
    [401, [], 'auth'], [403, [], 'auth'], [429, [], 'rate_limit'], [400, [], 'invalid_request'],
    [422, ['detail' => 'x'], 'invalid_request'], [404, [], 'invalid_request'], [500, [], 'server_error'],
    [502, [], 'server_error'], [202, [], 'timeout'], [400, ['error' => ['code' => 'content_filter']], 'content_filtered'],
]);

it('maps a content_filter finish without text to content_filtered with usage', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => ''], 'finish_reason' => 'content_filter']], 'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 0]])]);

    try {
        nvidiaProvider()->complete(driverRequest());
        $this->fail('expected exception');
    } catch (AiProviderException $e) {
        expect($e->reasonCode)->toBe('content_filtered')->and($e->usage->inputTokens)->toBe(7);
    }
});

// ---- Shared transport behaviour ----

it('maps transport errors to timeout or connection without keeping the previous exception', function (string $message, string $reason) {
    Http::fake(fn () => throw new ConnectionException($message.' '.DRIVER_KEY.' '.DRIVER_PROMPT));

    foreach ([geminiProvider(), nvidiaProvider()] as $provider) {
        try {
            $provider->complete(driverRequest());
            $this->fail('expected exception');
        } catch (AiProviderException $e) {
            expect($e->reasonCode)->toBe($reason)
                ->and($e->getPrevious())->toBeNull()
                ->and($e->getMessage())->not->toContain(DRIVER_KEY)->not->toContain(DRIVER_PROMPT);
        }
    }
})->with([
    ['cURL error 28: Operation timed out', 'timeout'],
    ['cURL error 6: Could not resolve host', 'connection'],
]);

it('maps a malformed body to bad_response', function () {
    Http::fake(['*' => Http::response('not json at all', 200)]);

    foreach ([geminiProvider(), nvidiaProvider()] as $provider) {
        expect(fn () => $provider->complete(driverRequest()))->toThrow(AiProviderException::class, 'bad_response');
    }

    Http::fake(['*' => Http::response(['unexpected' => true], 200)]);

    foreach ([geminiProvider(), nvidiaProvider()] as $provider) {
        expect(fn () => $provider->complete(driverRequest()))->toThrow(AiProviderException::class, 'bad_response');
    }
});

it('refuses to call without a key and writes nothing to the log', function () {
    Log::spy();
    Http::fake();

    $provider = new GeminiProvider(new AiConnection('AIP-0002', 'gemini', 'https://example.test', null, true));

    expect(fn () => $provider->complete(driverRequest()))->toThrow(AiProviderException::class, 'missing_credentials');
    Http::assertNothingSent();
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('info');
});

it('never exposes the key through the connection debug info', function () {
    $connection = new AiConnection('AIP-0002', 'gemini', 'https://example.test', DRIVER_KEY, true);

    expect(print_r($connection->__debugInfo(), true))->not->toContain(DRIVER_KEY);
});

it('builds connection drivers through the registry from DB connections', function () {
    $gemini = AiProvider::factory()->gemini()->withKey(DRIVER_KEY)->create();
    $nvidia = AiProvider::factory()->nvidia()->create();

    $registry = app(AiDriverRegistry::class);

    expect($registry->keys())->toBe(['anthropic', 'fake', 'gemini', 'openai', 'openai_compatible'])
        ->and($registry->make($gemini)->key())->toBe('gemini');

    expect(fn () => $registry->make($nvidia))->toThrow(AiProviderException::class, 'missing_credentials');
});

it('keeps drivers free of logging, cache, storage, db and env access', function () {
    $hits = shell_exec('grep -rn "Log::\|Cache::\|Storage::\|DB::\|env(" '.escapeshellarg(app_path('Ai/Providers')));

    expect(trim((string) $hits))->toBe('');
});

// ---- Connection request options ----

function openAiOk(): array
{
    return ['choices' => [['message' => ['content' => 'Hola'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2]];
}

it('merges connection request options into the OpenAI-compatible body and omits them when absent', function () {
    Http::fake(['*' => Http::response(openAiOk())]);

    (new OpenAiCompatibleProvider(new AiConnection('AIP-0003', 'openai_compatible', 'https://openrouter.ai/api/v1', DRIVER_KEY, false, ['provider' => ['zdr' => true]])))
        ->complete(driverRequest());
    nvidiaProvider()->complete(driverRequest());

    $bodies = [];
    Http::assertSent(function (Request $request) use (&$bodies) {
        $bodies[] = $request->data();

        return true;
    });

    expect($bodies[0]['provider'])->toBe(['zdr' => true])
        ->and($bodies[0]['model'])->toBe('the-model')
        ->and($bodies[1])->not->toHaveKey('provider');
});

it('never lets request options override controlled body fields or auth', function () {
    Http::fake(['*' => Http::response(openAiOk())]);

    $hostile = [
        'model' => 'evil', 'messages' => [], 'stream' => true, 'max_tokens' => 1, 'max_completion_tokens' => 1,
        'temperature' => 9, 'response_format' => ['type' => 'x'], 'Authorization' => 'Bearer evil', 'headers' => ['x' => 'y'],
        'api_key' => 'evil', 'provider' => ['require_parameters' => true],
    ];

    (new OpenAiCompatibleProvider(new AiConnection('AIP-0003', 'openai_compatible', 'https://openrouter.ai/api/v1', DRIVER_KEY, true, $hostile)))
        ->complete(driverRequest(['type' => 'object']));

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $body['model'] === 'the-model'
            && $body['temperature'] === 0.4
            && $body['max_tokens'] === 123
            && $body['response_format']['type'] === 'json_schema'
            && count($body['messages']) === 4
            && ! array_key_exists('stream', $body)
            && ! array_key_exists('max_completion_tokens', $body)
            && ! array_key_exists('Authorization', $body)
            && ! array_key_exists('headers', $body)
            && ! array_key_exists('api_key', $body)
            && $body['provider'] === ['require_parameters' => true]
            && $request->hasHeader('Authorization', 'Bearer '.DRIVER_KEY);
    });
});

it('passes the connection request options through the registry', function () {
    Http::fake(['*' => Http::response(openAiOk())]);
    $provider = AiProvider::factory()->nvidia()->withKey(DRIVER_KEY)->create([
        'base_url' => 'https://openrouter.ai/api/v1',
        'request_options' => ['provider' => ['zdr' => true]],
        'models' => [['id' => 'the-model', 'input_price_usd_per_million' => 1, 'output_price_usd_per_million' => 2]],
    ]);

    app(AiDriverRegistry::class)->make($provider)->complete(driverRequest());

    Http::assertSent(fn (Request $request) => $request->data()['provider'] === ['zdr' => true]);
});

// ---- Provider-reported cost ----

it('exposes usage.cost reported by an OpenAI-compatible provider and leaves it null otherwise', function () {
    Http::fake(['https://integrate.api.nvidia.com/*' => Http::sequence()
        ->push(['choices' => [['message' => ['content' => 'Hola'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2, 'cost' => 0.000123]])
        ->push(openAiOk()), 'https://generativelanguage.googleapis.com/*' => Http::response(geminiOk())]);

    $with = nvidiaProvider()->complete(driverRequest());
    $without = nvidiaProvider()->complete(driverRequest());

    expect($with->reportedCostUsd)->toBe(0.000123)->and($without->reportedCostUsd)->toBeNull();

    expect(geminiProvider()->complete(driverRequest())->reportedCostUsd)->toBeNull();
});

it('stores the reported cost next to the computed cost in the ledger, null when absent', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['choices' => [['message' => ['content' => 'Hola'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 1000, 'cost' => 0.0042]])
        ->push(openAiOk())]);
    $provider = AiProvider::factory()->nvidia()->withKey(DRIVER_KEY)->create();
    $model = $provider->modelIds()[0];
    $request = new AiRequest('s', [new AiMessage('user', 'hi')], new AiParams($model, 0.4, 100, 7));

    $metered = app(AiDriverRegistry::class)->make($provider);
    $metered->complete($request);
    $metered->complete($request);

    $rows = AiUsageEvent::query()->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and((float) $rows[0]->reported_cost_usd)->toBe(0.0042)
        ->and($rows[0]->cost_usd)->not->toBeNull()
        ->and($rows[1]->reported_cost_usd)->toBeNull();
});
