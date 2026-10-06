# Korozcolt AI Gateway

[![Latest Version on Packagist](https://img.shields.io/packagist/v/korozcolt/ai-gateway.svg?style=flat-square)](https://packagist.org/packages/korozcolt/ai-gateway)
[![Total Downloads](https://img.shields.io/packagist/dt/korozcolt/ai-gateway.svg?style=flat-square)](https://packagist.org/packages/korozcolt/ai-gateway)
[![License](https://img.shields.io/packagist/l/korozcolt/ai-gateway.svg?style=flat-square)](https://packagist.org/packages/korozcolt/ai-gateway)
[![Tests](https://img.shields.io/github/actions/workflow/status/korozcolt/ai-gateway/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/korozcolt/ai-gateway/actions/workflows/tests.yml)

A multi-provider AI gateway for Laravel. **Provider connections and their API tokens live in your database** (tokens encrypted with a dedicated key), so adding the tenth provider never touches `.env`. One contract, interchangeable drivers (OpenAI-compatible APIs such as **OpenRouter** and **NVIDIA Build**, **Gemini**), **multimodal** requests (text, images, PDFs), per-call **usage and cost metering**, and failures that expose closed reason codes only, never prompts, responses or keys.

Developed and used in production projects at **KOR Bytes S.A.S.** and published so anyone can evaluate and use it.

**Available on Packagist:** [`korozcolt/ai-gateway`](https://packagist.org/packages/korozcolt/ai-gateway) · **Source:** [github.com/korozcolt/ai-gateway](https://github.com/korozcolt/ai-gateway) · **License:** [MIT](LICENSE) · **Latest release:** see [Releases](https://github.com/korozcolt/ai-gateway/releases)

## Part of the Korozcolt / KOR Bytes ecosystem

| Package | What it is |
|---|---|
| **ai-gateway** (this package) | AI provider gateway for Laravel |
| [payments](https://github.com/korozcolt/payments) | Unified payment gateway (Wompi, MercadoPago, ePayco) for Laravel and any PHP project |

Both follow the same conventions: Composer vendor `korozcolt/`, PHP namespace `Korbytes\`, MIT license, Pest + Larastan + Pint, Keep a Changelog, GitHub Actions.

## Features

- **Connections in the database** - URL, token, models, prices and request options per provider, managed with Eloquent, a seed file, or your own admin panel
- **Encrypted tokens, one key outside the DB** - AES-256-GCM with a dedicated key (never `APP_KEY`), key id and **rotation** with retired keys
- **Driver architecture** - closed driver list in config; adding a driver is one class and one line
- **Multimodal** - `AiContentPart::text()`, `::image()`, `::file()` (PDF) mapped to each provider's wire format
- **Structured output** - JSON Schema via `response_format` when the connection supports it, otherwise requested in the system prompt; decoded JSON on the response
- **Usage metering** - one append-only ledger row per call (tokens, thinking/cached tokens, price snapshot, cost, provider-reported cost, latency, outcome), written through the queue so it survives a rolled-back caller transaction. No prompt or response content is ever stored
- **Closed failure taxonomy** - `AiFailure` reason codes; exceptions never carry prompt, response or key text
- **Per-model options** - e.g. `max_completion_tokens` and no temperature for reasoning models
- **Seeding** - import providers and tokens from a JSON file kept out of git; tokens are encrypted on insert; idempotent
- **Testable** - `FakeAiProvider` runs your whole app without network
- **Laravel 13** - PHP 8.3+, PostgreSQL

## Supported providers

| Provider | Driver | JSON Schema | Images / PDF | Status |
|---|---|---|---|---|
| **OpenRouter** | `openai_compatible` | Model dependent (`supports_json_schema`) | Yes (`image_url`, `file` data URIs) | Connected in KOR Bytes projects |
| **NVIDIA Build** | `openai_compatible` | Off: schema is requested in the system prompt | Model dependent | Connected in KOR Bytes projects. Hosted trial endpoints are for evaluation only: review the provider's retention and training terms before sending personal data |
| **OpenAI / Azure OpenAI (v1) / Mistral / Groq / any OpenAI-compatible API** | `openai_compatible` | Per connection | Per model | Same wire format; not verified one by one |
| **Google Gemini** | `gemini` | Yes (`responseJsonSchema`) | Yes (`inlineData`) | Covered by HTTP-faked tests; no live evaluation yet |
| **Fake** | `fake` | n/a | n/a | Deterministic driver for tests and local work |

Whether a given model supports vision, PDFs, JSON Schema or tool-free structured output depends on the model, not on this package: declare what each connection supports and verify with your own data.

## Requirements

- PHP 8.3+
- Laravel 13
- **PostgreSQL** (sequence-generated codes, `jsonb` and guard triggers)

## Installation

The package is published on [Packagist](https://packagist.org/packages/korozcolt/ai-gateway); no extra repository configuration is needed.

```bash
composer require korozcolt/ai-gateway
php artisan migrate
php artisan ai:generate-key
```

`ai:generate-key` prints the only environment variables you need; copy them into `.env`:

```env
AI_CREDENTIALS_KEY_ID=...
AI_CREDENTIALS_KEY=base64:...
```

Optionally publish the configuration and migrations:

```bash
php artisan vendor:publish --tag=ai-gateway-config
php artisan vendor:publish --tag=ai-gateway-migrations
```

See [INSTALL.md](INSTALL.md) for key rotation, database roles and queue notes.

## Quick Start

### 1. Add a provider and its token (seed file)

Create `database/seeders/data/ai-providers.json` (it is git-ignored in the package and should be in yours) and import it. Tokens are encrypted before they reach the database.

```json
{
  "providers": [{
    "name": "OpenRouter",
    "driver": "openai_compatible",
    "base_url": "https://openrouter.ai/api/v1",
    "api_key": "sk-or-...",
    "supports_json_schema": true,
    "is_active": true,
    "request_options": {"provider": {"data_collection": "deny", "zdr": true}},
    "models": [{"id": "vendor/model", "input_price_usd_per_million": 1.5, "output_price_usd_per_million": 6}]
  }]
}
```

```bash
php artisan ai:providers:import
```

or call `Korbytes\AiGateway\Database\Seeders\AiProvidersSeeder` from your `DatabaseSeeder`.

### 2. Make a call

```php
use Korbytes\AiGateway\Dto\{AiContentPart, AiMessage, AiParams, AiRequest};
use Korbytes\AiGateway\Facades\AiGateway;

$driver = AiGateway::provider('AIP-0002');   // already wrapped with the usage meter

$response = $driver->complete(new AiRequest(
    system: 'Extract the fields of the document.',
    messages: [new AiMessage('user', [
        AiContentPart::text('Identity card'),
        AiContentPart::image($base64, 'image/jpeg'),
    ])],
    params: new AiParams(model: 'vendor/model', temperature: 0.0, maxOutputTokens: 2000, timeoutSeconds: 60),
    jsonSchema: $schema,
    purpose: 'extraction',
    contextType: 'case',
    contextId: 123,
));

$response->text;        // raw text
$response->json;        // decoded array when a jsonSchema was requested (validate it in your app)
$response->costUsd;     // cost from the connection's price at call time
```

### 3. Handle failures

```php
use Korbytes\AiGateway\Exceptions\AiProviderException;

try {
    $response = $driver->complete($request);
} catch (AiProviderException $e) {
    $e->reasonCode;   // 'rate_limit', 'timeout', 'auth', ...
}
```

## Documentation

- [Installation Guide](INSTALL.md) - key generation and rotation, database roles, queues
- [Usage Guide](USAGE.md) - connections, requests, multimodal, structured output, metering, extending
- [Architecture](docs/ARCHITECTURE.md) - design decisions
- [Releasing](docs/RELEASING.md) - tagging and Packagist
- [Changelog](CHANGELOG.md) - version history

## Configuration

### Environment variables

```env
AI_CREDENTIALS_KEY_ID=...                 # generated by `php artisan ai:generate-key`
AI_CREDENTIALS_KEY=base64:...             # must differ from APP_KEY
AI_CREDENTIALS_RETIRED_KEYS={"id":"base64:..."}   # only while rotating
```

Provider tokens are **not** environment variables: they are stored encrypted in `ai_providers`. `config/ai-gateway.php` also exposes `drivers`, `credentials.distinct_from`, `usage.queue` and `seed_file`.

### Failure codes

| Code | Meaning |
|---|---|
| `auth` | Invalid or unauthorized credentials (401/402/403, Gemini `API_KEY_INVALID`) |
| `rate_limit` | 429 |
| `timeout` | Request timed out (or 202/408/504) |
| `invalid_request` | Provider rejected the request (400/404/413/416/422) |
| `content_filtered` | Blocked by the provider's safety filters |
| `server_error` | 5xx |
| `connection` | Network failure |
| `bad_response` | Unparseable or empty provider answer |
| `missing_credentials` | The connection has no token |
| `credentials_unavailable` | The token cannot be decrypted (key not configured, rotated without keeping the retired key, or corrupt ciphertext) |
| `unknown_driver` / `unknown_provider` / `provider_inactive` | Connection could not be resolved |
| `unknown_model` | The model is not declared in the connection's `models` |

## API Reference

### Facade

```php
AiGateway::available();                 // codes of active connections
AiGateway::modelsFor('AIP-0002');       // declared model ids
AiGateway::connection('AIP-0002');      // AiProvider model (active only)
AiGateway::provider('AIP-0002');        // metered driver
```

### DTOs

```php
new AiRequest(system: string, messages: list<AiMessage>, params: AiParams,
              jsonSchema: ?array, purpose: string = 'general', contextType: ?string, contextId: ?int);
new AiParams(model: string, temperature: float, maxOutputTokens: int, timeoutSeconds: int);
new AiMessage(role: 'user'|'assistant', content: string|list<AiContentPart>);
```

`AiResponse`: `text`, `json`, `inputTokens`, `outputTokens`, `thinkingTokens`, `cachedTokens`, `latencyMs`, `model`, `finishReason`, `costUsd`, `reportedCostUsd`.

## Extending

```php
use Korbytes\AiGateway\Providers\HttpProvider;

final class MyProvider extends HttpProvider
{
    public function key(): string { return 'my_provider'; }
    protected function send(PendingRequest $http, AiRequest $request): Response { /* ... */ }
    protected function parse(array $body): array { /* [text, AiUsage, AiFinishReason] */ }
}

// config/ai-gateway.php
'drivers' => [..., 'my_provider' => MyProvider::class],
```

## Roadmap

- Fallback chain across connections/models (today: do it in your application, see [USAGE.md](USAGE.md))
- Enforcement of `budget_usd` (stored today for reporting; not enforced)
- Optional Filament resource to manage connections
- Wider Laravel/PHP support (v0.x targets Laravel 13 / PHP 8.3)

## Testing

```bash
composer test
```

Tests need PostgreSQL (`ai_gateway_test`, see `phpunit.xml.dist`; override locally in an ignored `phpunit.xml`).

## Security

Never put provider tokens in `.env` or in your repository: use the encrypted database store. Keep the seed JSON out of git. If you discover a security issue, please follow [SECURITY.md](SECURITY.md).

## Credits

- [Korozcolt](https://github.com/korozcolt)
- [All Contributors](../../contributors)

## License

Released under the **MIT License** - Copyright (c) 2026 Korozcolt. You may use, copy, modify, merge, publish, distribute, sublicense and sell it, in personal, open-source and commercial projects, provided the copyright and license notice are included in all copies or substantial portions. The software is provided "as is", without warranty of any kind. See the [License File](LICENSE) for the full text.

Third-party AI providers you connect through this package have their own terms of service, pricing and data policies; you are responsible for complying with them.

---

## 🏢 Maintained by KOR Bytes S.A.S.

Need enterprise Laravel development, AI integrations, or SaaS solutions in Colombia and LATAM?

- 🌐 Website: [kor-bytes.com](https://kor-bytes.com)
- 💬 WhatsApp: [+57 304 397 8157](https://wa.me/573043978157)
- ✉️ Email: [gerencia@kor-bytes.com](mailto:gerencia@kor-bytes.com)
