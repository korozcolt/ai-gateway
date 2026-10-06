# Usage Guide

## Connections

A *connection* is a row in `ai_providers`: driver, base URL, encrypted token, declared models with prices, and options. Only **declared models** can be called; any other model is rejected with `unknown_model` before a request is sent, so cost is always computed from a known price.

```php
use Korbytes\AiGateway\Facades\AiGateway;

AiGateway::available();              // ['AIP-0001', 'AIP-0002']
AiGateway::modelsFor('AIP-0002');    // ['vendor/model']
$driver = AiGateway::provider('AIP-0002');   // active connections only, wrapped by the usage meter
```

Every driver leaves `AiDriverRegistry` wrapped by `MeteredProvider`; there is no unmetered path (a test enforces it).

## Requests

```php
use Korbytes\AiGateway\Dto\{AiMessage, AiParams, AiRequest};

$response = $driver->complete(new AiRequest(
    system: 'You are concise.',
    messages: [new AiMessage('user', 'Hello'), new AiMessage('assistant', 'Hi'), new AiMessage('user', 'Again')],
    params: new AiParams(model: 'vendor/model', temperature: 0.3, maxOutputTokens: 500, timeoutSeconds: 30),
));
```

`purpose` (free-form label, max 40 chars stored) and `contextType`/`contextId` (attribute the call to an entity of your app, e.g. a case or a tenant) are written to the usage ledger.

## Multimodal

```php
use Korbytes\AiGateway\Dto\AiContentPart;

new AiMessage('user', [
    AiContentPart::text('Read this document'),
    AiContentPart::image($base64Jpeg, 'image/jpeg'),
    AiContentPart::file($base64Pdf, 'application/pdf', 'certificate.pdf'),
]);
```

| Driver | Wire format |
|---|---|
| `openai_compatible` | `image_url` with a `data:` URI; `file` with `filename` and `file_data` data URI (the format OpenRouter accepts for PDFs) |
| `gemini` | `inlineData {mimeType, data}` |

Binary content is never written to logs, exceptions or the ledger (`AiContentPart::__debugInfo()` hides it). Whether a model accepts images or PDFs depends on the model.

## Structured output

```php
$response = $driver->complete(new AiRequest(..., jsonSchema: [
    'type' => 'object',
    'required' => ['name'],
    'properties' => ['name' => ['type' => 'string']],
]));

$response->json;   // array, or null if the model did not return valid JSON
```

- `supports_json_schema = true`: sent as `response_format: json_schema` (strict) on OpenAI-compatible APIs, `responseJsonSchema` on Gemini.
- `supports_json_schema = false`: the schema is appended to the system prompt and the answer is decoded (code fences are tolerated).

The gateway decodes JSON but **does not validate it against the schema**: validate in your application (and retry or reject).

Reasoning models can spend the whole token budget thinking and truncate the JSON: raise `maxOutputTokens` and check `finishReason` (`length`).

## Per-model options

```json
{"id": "o3", "max_tokens_param": "max_completion_tokens", "temperature": false,
 "input_price_usd_per_million": 2, "output_price_usd_per_million": 8}
```

`max_tokens_param` switches `max_tokens` to `max_completion_tokens`; `temperature: false` omits the temperature (models that reject it).

## Connection request options

`request_options` adds fields to the request body, for example OpenRouter privacy and routing preferences:

```json
{"provider": {"data_collection": "deny", "zdr": true}}
```

Check the provider's current documentation for the exact fields. Fields the application controls (`model`, `messages`, `stream`, token limits, `temperature`, `response_format`, `tools`, Gemini `contents`/`systemInstruction`/`generationConfig`) and anything that looks like a credential can never be set here: they are rejected on import and stripped again in the driver, and the application's own fields always win on a collision. For Gemini, options such as `safetySettings` are sent.

## Errors

```php
use Korbytes\AiGateway\Exceptions\AiProviderException;

try { $driver->complete($request); }
catch (AiProviderException $e) {
    match ($e->reasonCode) {
        'rate_limit', 'timeout', 'server_error', 'connection' => /* retry / fall back */,
        'auth', 'credentials_unavailable', 'missing_credentials' => /* alert an operator */,
        'content_filtered', 'invalid_request', 'unknown_model' => /* fix the request */,
        default => throw $e,
    };
}
```

`$e->usage` carries the tokens the provider reported when a failed call still bills (for example a blocked prompt).

## Usage ledger

```php
use Korbytes\AiGateway\Models\AiUsageEvent;

AiUsageEvent::query()
    ->where('context_type', 'case')->where('context_id', 123)
    ->sum('cost_usd');
```

Columns: `code` (AUE-00000001), `ai_provider_id`, `context_type`, `context_id`, `model`, `purpose`, token counters (`input`, `output`, `thinking`, `cached`), price snapshot (`price_*_usd_per_million`), `cost_usd` (computed from the price at call time), `reported_cost_usd` (cost the provider reported, e.g. OpenRouter), `latency_ms`, `outcome` (`ok` or a failure code, `internal_error` for unexpected driver failures) and `occurred_at`.

The table is append-only (triggers reject UPDATE and DELETE). Retention: `SELECT ai_usage_events_purge('2026-01-01')` deletes rows older than the cutoff.

## Fallback across connections

v0.1 has no fallback decorator. Until it exists, do it in your application:

```php
foreach (['AIP-0002', 'AIP-0003'] as $code) {
    try { return AiGateway::provider($code)->complete($request); }
    catch (AiProviderException $e) {
        if (! in_array($e->reasonCode, ['rate_limit', 'timeout', 'server_error', 'connection'], true)) throw $e;
    }
}
```

Note that each connection declares its own models: build the request with the model of the connection you call.

## Testing your application

```php
use Korbytes\AiGateway\Providers\FakeAiProvider;
use Korbytes\AiGateway\Dto\AiResponse;

FakeAiProvider::queue(new AiResponse('hello', ['x' => 1], 10, 5, 0, 'fake-model'));
// ... run your code against a connection with driver `fake`
FakeAiProvider::requests();   // the AiRequest objects it received
FakeAiProvider::reset();
```

`AiProvider::factory()->create()` creates a fake connection; `->gemini()`, `->nvidia()`, `->withKey('...')` and `->inactive()` are available states.

## Custom drivers

Implement `Korbytes\AiGateway\Contracts\AiProviderInterface` (or extend `HttpProvider` for HTTP APIs: it handles timing, error classification and JSON decoding) and register it in `config/ai-gateway.php` under `drivers`. Never construct drivers yourself in application code: go through the registry so they are metered.
