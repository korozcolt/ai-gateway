# korbytes/ai-gateway

Capa de IA para Laravel 13: **conexiones a proveedores en base de datos con tokens cifrados**, drivers intercambiables
(OpenAI-compatible → OpenRouter, NVIDIA Build, OpenAI, Azure v1, Mistral, Groq…; Gemini; Fake), peticiones **multimodales**
(texto, imágenes, PDF), medición de uso/costo por llamada y excepciones que nunca llevan prompts, respuestas ni claves.

Requiere **PostgreSQL** (secuencias, `jsonb`, triggers) y Laravel 13 / PHP 8.3+.

> **English:** a Laravel 13 gateway for AI providers. Provider connections and their API tokens live in the database (encrypted with a dedicated key, never in `.env`); OpenAI-compatible drivers (OpenRouter, NVIDIA Build, OpenAI, Azure, Mistral, Groq…), Gemini and a fake driver; multimodal requests (text, images, PDFs); per-call usage/cost metering; failures expose closed reason codes only, never prompts, responses or keys. See the sections below (in Spanish) for installation, seeding and usage.

## Instalación

```bash
composer require korbytes/ai-gateway
php artisan migrate
php artisan ai:generate-key      # imprime AI_CREDENTIALS_KEY_ID y AI_CREDENTIALS_KEY: cópielos al .env
```

La única variable de entorno es la **clave que cifra los tokens** (`AI_CREDENTIALS_KEY_ID`, `AI_CREDENTIALS_KEY`, y
`AI_CREDENTIALS_RETIRED_KEYS` en rotación). Los proveedores NO van en `.env`: se guardan en la tabla `ai_providers`.
La clave debe ser distinta de `APP_KEY` (y de las claves listadas en `ai-gateway.credentials.distinct_from`).

## Cargar proveedores y tokens (siembra)

Cree `database/seeders/data/ai-providers.json` (ignorado por git) y ejecute `php artisan ai:providers:import`
o llame a `Korbytes\AiGateway\Database\Seeders\AiProvidersSeeder` desde su `DatabaseSeeder`. Los tokens se cifran al insertarse.

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

Es idempotente (clave `driver` + `name`); el token existente se conserva salvo `--rotate-keys`. `request_options` no puede
fijar `model`, `messages`, `tools`, `response_format` ni credenciales. También puede crear conexiones desde su panel
administrativo con `AiProvider::create([...])` + `$provider->replaceApiKey($token)`.

## Uso

```php
use Korbytes\AiGateway\AiProviderManager;
use Korbytes\AiGateway\Dto\{AiContentPart, AiMessage, AiParams, AiRequest};

$driver = app(AiProviderManager::class)->provider('AIP-0002');   // ya viene envuelto con el medidor de uso

$response = $driver->complete(new AiRequest(
    system: 'Extrae los campos del documento.',
    messages: [new AiMessage('user', [
        AiContentPart::text('Cédula del compareciente'),
        AiContentPart::image($base64, 'image/jpeg'),
    ])],
    params: new AiParams(model: 'vendor/model', temperature: 0.0, maxOutputTokens: 2000, timeoutSeconds: 60),
    jsonSchema: $schema,
    purpose: 'extraction',
    contextType: 'tramite',
    contextId: 123,
));

$response->json;       // array decodificado si se pidió jsonSchema
$response->costUsd;    // costo calculado con el precio vigente de la conexión
```

Errores: `AiProviderException` con `reasonCode` cerrado (`AiFailure`: auth, rate_limit, timeout, invalid_request,
content_filtered, server_error, connection, bad_response, missing_credentials, unknown_driver, unknown_provider,
provider_inactive, unknown_model). Nunca incluye texto del prompt, de la respuesta ni la clave.

## Nuevo driver

Una clase que implemente `AiProviderInterface` (o extienda `HttpProvider`) y una línea en `config('ai-gateway.drivers')`.

## Pruebas

Requieren PostgreSQL (`ai_gateway_test`; ver `phpunit.xml`): `vendor/bin/pest`. `FakeAiProvider` permite probar sin red.

## Licencia

MIT. Ver [LICENSE](LICENSE).
