# Security Policy

## Supported versions

Security fixes are released for the latest minor version.

## Reporting a vulnerability

Please **do not open a public issue** for security problems. Use GitHub's private vulnerability reporting ("Security" tab → "Report a vulnerability") or email [gerencia@kor-bytes.com](mailto:gerencia@kor-bytes.com) with a description and, if possible, a reproduction. We aim to acknowledge reports within a few business days.

## Design notes relevant to security

- Provider tokens are stored encrypted (AES-256-GCM) with a dedicated key that must differ from `APP_KEY`; the key never falls back to `APP_KEY`.
- Tokens are not mass-assignable, are hidden from serialization, `toArray()`, `serialize()` and debug dumps, and exist in clear text only in memory while a call is made.
- Exceptions carry closed reason codes only: never prompt, response or key text. Binary content of multimodal parts is hidden from dumps.
- The usage ledger stores counters, prices, cost, latency and outcome codes: never prompt or response content.
- `request_options` cannot set model, messages, token limits, response format, tools or credential-like fields.
- Keep your seed JSON (it contains clear-text tokens) out of version control.
- Sending data to an AI provider is a data transfer to a third party: review each provider's retention, training and data-location terms before sending personal or confidential data.
