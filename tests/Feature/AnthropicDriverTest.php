<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Korbytes\AiGateway\AiConnection;
use Korbytes\AiGateway\AiFinishReason;
use Korbytes\AiGateway\Contracts\ListsModels;
use Korbytes\AiGateway\Dto\AiContentPart;
use Korbytes\AiGateway\Dto\AiMessage;
use Korbytes\AiGateway\Dto\AiParams;
use Korbytes\AiGateway\Dto\AiRequest;
use Korbytes\AiGateway\Exceptions\AiProviderException;
use Korbytes\AiGateway\Providers\AnthropicProvider;

const ANTH_KEY = 'sk-test-anthropic-key-77aa';
const ANTH_PROMPT = 'ANTHROPIC-DISTINCTIVE-PROMPT-55';

function anthropicProvider(?string $key = ANTH_KEY, array $options = []): AnthropicProvider
{
    return new AnthropicProvider(new AiConnection('AIP-0004', 'anthropic', 'https://api.anthropic.com/v1', $key, true, $options));
}

function anthropicRequest(?array $schema = null, ?array $messages = null, float $temperature = 0.4): AiRequest
{
    return new AiRequest(
        'Be brief. '.ANTH_PROMPT,
        $messages ?? [new AiMessage('user', 'hello'), new AiMessage('assistant', 'hi'), new AiMessage('user', 'again')],
        new AiParams('claude-test', $temperature, 123, 7),
        $schema,
    );
}

function anthropicOk(array $content = [['type' => 'text', 'text' => 'Hola']], string $stop = 'end_turn', array $usage = []): array
{
    return [
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'content' => $content, 'stop_reason' => $stop,
        'usage' => $usage + ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

function anthropicFailure(callable $call): string
{
    try {
        $call();
    } catch (AiProviderException $e) {
        expect($e->getMessage())->not->toContain(ANTH_KEY)->not->toContain(ANTH_PROMPT);

        return $e->reasonCode;
    }

    return 'no-exception';
}

beforeEach(fn () => Http::preventStrayRequests());

it('sends the exact Anthropic request: url, headers, system apart, only user/assistant messages', function () {
    Http::fake(['*' => Http::response(anthropicOk())]);

    $response = anthropicProvider()->complete(anthropicRequest());

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->method() === 'POST'
            && $request->header('x-api-key') === [ANTH_KEY]
            && $request->header('anthropic-version') === ['2023-06-01']
            && $request->header('Authorization') === []
            && $body['model'] === 'claude-test'
            && $body['max_tokens'] === 123
            && $body['temperature'] === 0.4
            && $body['system'] === 'Be brief. '.ANTH_PROMPT
            && array_column($body['messages'], 'role') === ['user', 'assistant', 'user']
            && $body['messages'][0]['content'] === 'hello'
            && ! isset($body['tools'], $body['tool_choice']);
    });
    expect($response->text)->toBe('Hola')->and($response->json)->toBeNull()
        ->and($response->finishReason)->toBe(AiFinishReason::Stop);
});

it('clamps the temperature to the 0..1 Anthropic range', function () {
    Http::fake(['*' => Http::response(anthropicOk())]);

    anthropicProvider()->complete(anthropicRequest(temperature: 1.8));

    Http::assertSent(fn (Request $r) => $r->data()['temperature'] === 1.0);
});

it('sends images as base64 sources and PDFs as documents', function () {
    Http::fake(['*' => Http::response(anthropicOk())]);
    $messages = [new AiMessage('user', [
        AiContentPart::text('look'),
        AiContentPart::image('QUJD', 'image/png'),
        AiContentPart::file('UERG', 'application/pdf', 'a.pdf'),
    ])];

    anthropicProvider()->complete(anthropicRequest(messages: $messages));

    Http::assertSent(function (Request $request) {
        $parts = $request->data()['messages'][0]['content'];

        return $parts[0] === ['type' => 'text', 'text' => 'look']
            && $parts[1] === ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'QUJD']]
            && $parts[2] === ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => 'UERG']];
    });
});

it('forces a tool when a schema is given and returns the tool input as decoded json', function () {
    $schema = ['type' => 'object', 'properties' => ['n' => ['type' => 'string']]];
    Http::fake(['*' => Http::response(anthropicOk([['type' => 'tool_use', 'id' => 't1', 'name' => 'output', 'input' => ['n' => 'ñandú']]], 'tool_use'))]);

    $response = anthropicProvider()->complete(anthropicRequest($schema));

    Http::assertSent(fn (Request $r) => $r->data()['tools'] === [['name' => 'output', 'description' => 'Return the structured result.', 'input_schema' => $schema]]
        && $r->data()['tool_choice'] === ['type' => 'tool', 'name' => 'output']);
    expect($response->json)->toBe(['n' => 'ñandú'])->and($response->finishReason)->toBe(AiFinishReason::Stop);
});

it('concatenates text blocks when there is no schema', function () {
    Http::fake(['*' => Http::response(anthropicOk([['type' => 'text', 'text' => 'Hola '], ['type' => 'text', 'text' => 'mundo']]))]);

    expect(anthropicProvider()->complete(anthropicRequest())->text)->toBe('Hola mundo');
});

it('maps usage with cache reads left separate from input tokens', function () {
    Http::fake(['*' => Http::response(anthropicOk(usage: ['input_tokens' => 40, 'output_tokens' => 9, 'cache_read_input_tokens' => 25, 'cache_creation_input_tokens' => 3]))]);

    $response = anthropicProvider()->complete(anthropicRequest());

    expect($response->inputTokens)->toBe(40)->and($response->outputTokens)->toBe(9)->and($response->cachedTokens)->toBe(25);
});

it('maps stop_reason to the finish reason', function (string $stop, AiFinishReason $expected) {
    Http::fake(['*' => Http::response(anthropicOk(stop: $stop))]);

    expect(anthropicProvider()->complete(anthropicRequest())->finishReason)->toBe($expected);
})->with([
    ['end_turn', AiFinishReason::Stop],
    ['stop_sequence', AiFinishReason::Stop],
    ['tool_use', AiFinishReason::Stop],
    ['max_tokens', AiFinishReason::Length],
    ['pause_turn', AiFinishReason::Other],
]);

it('raises content_filtered on an empty refusal and keeps the reported usage', function () {
    Http::fake(['*' => Http::response(anthropicOk([], 'refusal'))]);

    try {
        anthropicProvider()->complete(anthropicRequest());
        $caught = null;
    } catch (AiProviderException $e) {
        $caught = $e;
    }

    expect($caught?->reasonCode)->toBe('content_filtered')->and($caught?->usage?->inputTokens)->toBe(10);
});

it('classifies HTTP errors without leaking the key or the prompt', function (int $status, string $reason) {
    Http::fake(['*' => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => ANTH_PROMPT.ANTH_KEY]], $status)]);

    expect(anthropicFailure(fn () => anthropicProvider()->complete(anthropicRequest())))->toBe($reason);
})->with([
    [401, 'auth'], [403, 'auth'], [429, 'rate_limit'], [500, 'server_error'], [529, 'server_error'],
    [413, 'invalid_request'], [504, 'timeout'], [408, 'timeout'],
]);

it('maps a connection failure to timeout or connection', function (string $message, string $reason) {
    Http::fake(['*' => fn () => throw new ConnectionException($message.' '.ANTH_KEY)]);

    expect(anthropicFailure(fn () => anthropicProvider()->complete(anthropicRequest())))->toBe($reason);
})->with([
    ['cURL error 28: Operation timed out', 'timeout'],
    ['cURL error 6: could not resolve', 'connection'],
]);

it('answers bad_response when a 200 has no content array', function () {
    Http::fake(['*' => Http::response(['type' => 'message'])]);

    expect(anthropicFailure(fn () => anthropicProvider()->complete(anthropicRequest())))->toBe('bad_response');
});

it('refuses to call without a key and logs nothing', function () {
    Log::spy();
    Http::fake();

    expect(anthropicFailure(fn () => anthropicProvider(null)->complete(anthropicRequest())))->toBe('missing_credentials');
    Http::assertNothingSent();
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
});

it('sends connection options but never lets them override system, version or the app fields', function () {
    Http::fake(['*' => Http::response(anthropicOk())]);

    anthropicProvider(options: ['metadata' => ['user_id' => 'u1'], 'system' => 'evil', 'anthropic-version' => 'x', 'thinking' => ['type' => 'enabled'], 'model' => 'other'])
        ->complete(anthropicRequest());

    Http::assertSent(fn (Request $r) => $r->data()['metadata'] === ['user_id' => 'u1']
        && $r->data()['system'] === 'Be brief. '.ANTH_PROMPT
        && $r->data()['model'] === 'claude-test'
        && ! isset($r->data()['thinking'], $r->data()['anthropic-version'])
        && $r->header('anthropic-version') === ['2023-06-01']);
});

it('lists models following the pagination cursor', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['data' => [['id' => 'claude-a'], ['id' => 'claude-b']], 'has_more' => true, 'last_id' => 'claude-b'])
        ->push(['data' => [['id' => 'claude-c']], 'has_more' => false, 'last_id' => 'claude-c']),
    ]);

    $provider = anthropicProvider();

    expect($provider)->toBeInstanceOf(ListsModels::class)
        ->and($provider->listModels())->toBe(['claude-a', 'claude-b', 'claude-c']);

    $urls = [];
    Http::assertSentCount(2);
    Http::assertSent(function (Request $r) use (&$urls) {
        $urls[] = $r->url();

        return $r->method() === 'GET' && $r->header('x-api-key') === [ANTH_KEY] && $r->header('anthropic-version') === ['2023-06-01'];
    });
    expect($urls[0])->toContain('https://api.anthropic.com/v1/models')->toContain('limit=100')->not->toContain('after_id')
        ->and($urls[1])->toContain('after_id=claude-b');
});

it('fails model listing with closed reasons', function () {
    Http::fake(['*' => Http::response(['error' => 'x'], 401)]);
    expect(anthropicFailure(fn () => anthropicProvider()->listModels()))->toBe('auth');

    expect(anthropicFailure(fn () => anthropicProvider(null)->listModels()))->toBe('missing_credentials');
});

it('maps a model listing timeout to timeout', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);

    expect(anthropicFailure(fn () => anthropicProvider()->listModels()))->toBe('timeout');
});
