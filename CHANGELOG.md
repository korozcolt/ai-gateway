# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- Documentation: Packagist installation, Tests badge, expanded license section; copyright holder aligned with the other Korozcolt packages.

## [0.1.0] - 2026-10-06

First public release. Extracted from the AI layer of a KOR Bytes production project and generalized.

### Added
- Provider connections stored in the database (`ai_providers`) with tokens encrypted by a dedicated AES-256-GCM key (key id, rotation with retired keys, `php artisan ai:generate-key`).
- Drivers: `openai_compatible` (OpenRouter, NVIDIA Build, OpenAI, Azure OpenAI v1, Mistral, Groq...), `gemini` and `fake`; closed driver list in `config/ai-gateway.php`.
- Multimodal messages: `AiContentPart::text()`, `::image()`, `::file()` for OpenAI-compatible APIs and Gemini.
- Structured output (`response_format` JSON Schema or schema-in-prompt) with decoded JSON on the response.
- Usage metering: `MeteredProvider` + append-only `ai_usage_events` ledger (tokens, thinking/cached tokens, price snapshot, cost, provider-reported cost, latency, outcome, optional `context_type`/`context_id`), retention purge function.
- Closed failure taxonomy (`AiFailure`) and `AiProviderException` that never carries prompt, response or key text.
- `AiGateway` facade, `AiProviderManager`, `ai:providers:import` command, `AiProvidersSeeder` and `ProviderImporter` (idempotent, transactional, keeps settings the file omits).
- Per-model options (`max_tokens_param`, `temperature`) for reasoning models; `request_options` with a denylist of app-controlled fields and credentials.
- Pest test suite on PostgreSQL (Orchestra Testbench), Larastan level 6, Pint, GitHub Actions.

### Security
- Calls for models not declared in the connection are rejected before any request is sent.
- Key/decryption errors surface as `AiProviderException` (`credentials_unavailable`); the usage ledger job ignores `after_commit` and over-long labels are truncated so a billed call is never left unmetered.
