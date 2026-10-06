# Architecture

## Goals

1. Add or change an AI provider **without code changes or `.env` growth**.
2. Keep provider tokens out of the repository, the environment and logs.
3. Meter every call; never store prompts or responses in the ledger.
4. Fail with closed, safe reason codes.
5. Make the whole thing testable without network.

## Request flow

```
AiGateway::provider('AIP-0002')
  └─ AiProviderManager        resolves the ACTIVE connection by code
      └─ AiDriverRegistry     the only place a connection becomes a driver
          ├─ decrypts the token (AiCredentialCipher + AiKeyProvider)
          ├─ builds AiConnection (token lives only in memory)
          ├─ instantiates the driver from config('ai-gateway.drivers')
          └─ wraps it in MeteredProvider
              ├─ rejects models the connection does not declare (unknown_model)
              ├─ calls the driver (openai_compatible | gemini | fake)
              └─ records one ledger row (success or failure) via a queued job
```

## Decisions

| Decision | Reason |
|---|---|
| Connections and tokens in the database | Many providers would make `.env` unmanageable; rows can be managed from code, a seed file or an admin panel |
| One dedicated key outside the DB | The key that encrypts the tokens cannot live in the database it protects; it must differ from `APP_KEY` so a leaked app key does not expose provider tokens |
| Closed driver list in config | A connection cannot name an arbitrary class; adding a driver is explicit |
| One `openai_compatible` driver | OpenRouter, NVIDIA Build, OpenAI, Azure v1, Mistral and Groq share the chat/completions format; differences are data (URL, models, options), not code |
| Decorator for metering applied in the registry | No caller or driver can skip it |
| Ledger written through the queue, ignoring `after_commit` | The row must survive a rolled-back caller transaction |
| Exceptions with reason codes only | Prompts and responses may hold personal data; keys must never reach logs |
| `purpose` and context as free text/ids | Each application defines its own labels and entities |
| Declared models only | Guarantees a price snapshot for every call |
| `request_options` with a denylist | Allows provider-specific features (privacy/routing flags) without letting data override the fields the app controls |
| PostgreSQL only | Sequence-generated immutable codes, `jsonb`, guard triggers |

## Known limits (v0.1)

- No fallback decorator and no budget enforcement (`budget_usd` is informational).
- The gateway decodes structured output but does not validate it against the schema.
- Laravel 13 / PHP 8.3 only.
- Gemini is covered by HTTP-faked tests; no live evaluation yet.
