<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Korbytes\AiGateway\AiConnection;
use Korbytes\AiGateway\AiDriverRegistry;
use Korbytes\AiGateway\AiFinishReason;
use Korbytes\AiGateway\Contracts\ListsModels;
use Korbytes\AiGateway\Dto\AiContentPart;
use Korbytes\AiGateway\Dto\AiMessage;
use Korbytes\AiGateway\Dto\AiParams;
use Korbytes\AiGateway\Dto\AiRequest;
use Korbytes\AiGateway\Exceptions\AiProviderException;
use Korbytes\AiGateway\Models\AiProvider;
use Korbytes\AiGateway\Models\AiUsageEvent;
use Korbytes\AiGateway\Providers\OpenAiCompatibleProvider;
use Korbytes\AiGateway\Providers\OpenAiProvider;

const OAI_KEY = 'sk-test-openai-key-31bc';
const OAI_PROMPT = 'OPENAI-DISTINCTIVE-PROMPT-88';

/** @param array<string, array<string, mixed>> $modelOptions */
function openAiProvider(bool $json = true, array $modelOptions = [], ?string $key = OAI_KEY, string $baseUrl = 'https://api.openai.com/v1'): OpenAiProvider
{
    return new OpenAiProvider(new AiConnection('AIP-0005', 'openai', $baseUrl, $key, $json, [], $modelOptions));
}

function openAiRequest(string $model = 'gpt-4o', ?array $schema = null, ?array $messages = null): AiRequest
{
    return new AiRequest(
        'Be brief. '.OAI_PROMPT,
        $messages ?? [new AiMessage('user', 'hello')],
        new AiParams($model, 0.4, 123, 7),
        $schema,
    );
}

function openAiBody(array $usage = [], string $finish = 'stop', string $text = 'Hola'): array
{
    return [
        'choices' => [['message' => ['content' => $text], 'finish_reason' => $finish]],
        'usage' => $usage + ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ];
}

function openAiFailure(callable $call): string
{
    try {
        $call();
    } catch (AiProviderException $e) {
        expect($e->getMessage())->not->toContain(OAI_KEY)->not->toContain(OAI_PROMPT);

        return $e->reasonCode;
    }

    return 'no-exception';
}

beforeEach(fn () => Http::preventStrayRequests());

it('sends the exact OpenAI request for a classic model: bearer, max_tokens and temperature', function () {
    Http::fake(['*' => Http::response(openAiBody())]);

    $response = openAiProvider()->complete(openAiRequest());

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request->header('Authorization') === ['Bearer '.OAI_KEY]
            && $body['model'] === 'gpt-4o'
            && $body['max_tokens'] === 123
            && $body['temperature'] === 0.4
            && ! isset($body['max_completion_tokens'])
            && $body['messages'][0] === ['role' => 'system', 'content' => 'Be brief. '.OAI_PROMPT]
            && $body['messages'][1] === ['role' => 'user', 'content' => 'hello'];
    });
    expect($response->text)->toBe('Hola')->and($response->finishReason)->toBe(AiFinishReason::Stop);
});

it('falls back to the official base URL when the connection has none', function () {
    Http::fake(['*' => Http::response(openAiBody())]);

    openAiProvider(baseUrl: '')->complete(openAiRequest());

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/chat/completions');
});

it('uses max_completion_tokens and no temperature for reasoning families', function (string $model) {
    Http::fake(['*' => Http::response(openAiBody())]);

    openAiProvider()->complete(openAiRequest($model));

    Http::assertSent(fn (Request $r) => $r->data()['max_completion_tokens'] === 123
        && ! isset($r->data()['max_tokens'], $r->data()['temperature']));
})->with(['gpt-5', 'gpt-5-mini', 'o1', 'o3-mini', 'o4-mini', 'openai/gpt-5']);

it('lets the declared model options win over the family defaults', function () {
    Http::fake(['*' => Http::response(openAiBody())]);
    $options = ['gpt-5' => ['max_tokens_param' => 'max_tokens', 'temperature' => true]];

    openAiProvider(modelOptions: $options)->complete(openAiRequest('gpt-5'));
    openAiProvider(modelOptions: ['gpt-4o' => ['max_tokens_param' => 'max_completion_tokens', 'temperature' => false]])->complete(openAiRequest('gpt-4o'));

    $sent = [];
    Http::assertSent(function (Request $r) use (&$sent) {
        $sent[] = $r->data();

        return true;
    });
    expect($sent[0]['max_tokens'])->toBe(123)->and($sent[0]['temperature'])->toBe(0.4)->and($sent[0])->not->toHaveKey('max_completion_tokens')
        ->and($sent[1]['max_completion_tokens'])->toBe(123)->and($sent[1])->not->toHaveKey('temperature')->not->toHaveKey('max_tokens');
});

it('keeps the openai_compatible driver sending max_tokens and temperature by default', function () {
    Http::fake(['*' => Http::response(openAiBody())]);

    (new OpenAiCompatibleProvider(new AiConnection('AIP-0003', 'openai_compatible', 'https://x.example/v1', 'k', false)))->complete(openAiRequest('gpt-5'));

    Http::assertSent(fn (Request $r) => $r->data()['max_tokens'] === 123 && $r->data()['temperature'] === 0.4 && ! isset($r->data()['max_completion_tokens']));
});

it('sends a strict json_schema when supported and the schema in the prompt when not', function () {
    Http::fake(['*' => Http::response(openAiBody(text: '{"n":"x"}'))]);
    $schema = ['type' => 'object', 'properties' => ['n' => ['type' => 'string']]];

    $strict = openAiProvider(true)->complete(openAiRequest(schema: $schema));
    openAiProvider(false)->complete(openAiRequest(schema: $schema));

    $sent = [];
    Http::assertSent(function (Request $r) use (&$sent) {
        $sent[] = $r->data();

        return true;
    });
    expect($strict->json)->toBe(['n' => 'x'])
        ->and($sent[0]['response_format'])->toBe(['type' => 'json_schema', 'json_schema' => ['name' => 'output', 'strict' => true, 'schema' => $schema]])
        ->and($sent[1])->not->toHaveKey('response_format')
        ->and($sent[1]['messages'][0]['content'])->toContain('JSON Schema')->toContain('"properties"');
});

it('sends images and files as data URIs', function () {
    Http::fake(['*' => Http::response(openAiBody())]);
    $messages = [new AiMessage('user', [AiContentPart::text('look'), AiContentPart::image('QUJD', 'image/png'), AiContentPart::file('UERG', 'application/pdf', 'a.pdf')])];

    openAiProvider()->complete(openAiRequest(messages: $messages));

    Http::assertSent(function (Request $r) {
        $parts = $r->data()['messages'][1]['content'];

        return $parts[0] === ['type' => 'text', 'text' => 'look']
            && $parts[1] === ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,QUJD']]
            && $parts[2] === ['type' => 'file', 'file' => ['filename' => 'a.pdf', 'file_data' => 'data:application/pdf;base64,UERG']];
    });
});

it('separates reasoning and cached tokens in the usage', function () {
    Http::fake(['*' => Http::response(openAiBody([
        'prompt_tokens' => 100, 'completion_tokens' => 60,
        'completion_tokens_details' => ['reasoning_tokens' => 40],
        'prompt_tokens_details' => ['cached_tokens' => 30],
    ]))]);

    $response = openAiProvider()->complete(openAiRequest('o3'));

    expect($response->inputTokens)->toBe(100)->and($response->outputTokens)->toBe(20)
        ->and($response->thinkingTokens)->toBe(40)->and($response->cachedTokens)->toBe(30);
});

it('raises content_filtered for an empty filtered answer', function () {
    Http::fake(['*' => Http::response(openAiBody(finish: 'content_filter', text: ''))]);

    expect(openAiFailure(fn () => openAiProvider()->complete(openAiRequest())))->toBe('content_filtered');
});

it('classifies a 400 content_filter error as content_filtered', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'content_filter']], 400)]);

    expect(openAiFailure(fn () => openAiProvider()->complete(openAiRequest())))->toBe('content_filtered');
});

it('classifies HTTP errors without leaking the key or the prompt', function (int $status, string $reason) {
    Http::fake(['*' => Http::response(['error' => ['message' => OAI_PROMPT.OAI_KEY]], $status)]);

    expect(openAiFailure(fn () => openAiProvider()->complete(openAiRequest())))->toBe($reason);
})->with([
    [401, 'auth'], [403, 'auth'], [429, 'rate_limit'], [500, 'server_error'], [503, 'server_error'], [504, 'timeout'],
]);

it('maps a connection failure to timeout or connection', function (string $message, string $reason) {
    Http::fake(['*' => fn () => throw new ConnectionException($message.' '.OAI_KEY)]);

    expect(openAiFailure(fn () => openAiProvider()->complete(openAiRequest())))->toBe($reason);
})->with([
    ['cURL error 28: Operation timed out', 'timeout'],
    ['cURL error 6: could not resolve', 'connection'],
]);

it('lists models sorted and fails with closed reasons', function () {
    Http::fake(['*' => Http::response(['object' => 'list', 'data' => [['id' => 'gpt-4o'], ['id' => 'gpt-5'], ['id' => 'a-model']]])]);

    $provider = openAiProvider();

    expect($provider)->toBeInstanceOf(ListsModels::class)->and($provider->listModels())->toBe(['a-model', 'gpt-4o', 'gpt-5']);
    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://api.openai.com/v1/models' && $r->header('Authorization') === ['Bearer '.OAI_KEY]);
    expect(openAiFailure(fn () => openAiProvider(key: null)->listModels()))->toBe('missing_credentials');
});

it('maps a model listing auth error', function () {
    Http::fake(['*' => Http::response(['error' => 'x'], 401)]);

    expect(openAiFailure(fn () => openAiProvider()->listModels()))->toBe('auth');
});

it('resolves through the registry and meters usage with the connection price', function () {
    Http::fake(['*' => Http::response(openAiBody(['prompt_tokens' => 1000, 'completion_tokens' => 500]))]);
    $provider = AiProvider::factory()->create([
        'driver' => 'openai',
        'base_url' => 'https://api.openai.com/v1',
        'models' => [['id' => 'gpt-4o', 'input_price_usd_per_million' => 2.0, 'output_price_usd_per_million' => 8.0]],
    ]);
    $provider->replaceApiKey(OAI_KEY);

    $driver = app(AiDriverRegistry::class)->make($provider->fresh());
    $response = $driver->complete(openAiRequest());

    // 1000 x 2.00 + 500 x 8.00 = 6000 per million = 0.006 USD
    $event = AiUsageEvent::query()->firstOrFail();
    expect($driver->key())->toBe('openai')
        ->and($response->costUsd)->toEqualWithDelta(0.006, 1e-9)
        ->and((float) $event->cost_usd)->toEqualWithDelta(0.006, 1e-9)
        ->and($event->outcome)->toBe('ok')
        ->and(json_encode($event->getAttributes()))->not->toContain(OAI_KEY)->not->toContain(OAI_PROMPT);
});
