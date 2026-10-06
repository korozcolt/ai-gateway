# Releasing

1. Make sure `main` is green (`composer test`, `composer analyse`, `composer format -- --test`).
2. Move the `[Unreleased]` entries of `CHANGELOG.md` under a new version heading with the date.
3. Commit, then tag and push: `git tag v0.1.0 && git push origin v0.1.0`.
4. Create the GitHub release from the tag (copy the changelog section).

## Packagist

`korozcolt/ai-gateway` is published at https://packagist.org/packages/korozcolt/ai-gateway and updates automatically through the GitHub webhook (a push to `main` refreshes `dev-main`; a pushed tag publishes a release). Packagist shows the README of the latest release, so documentation-only changes appear on the package page after the next tag.

First-time setup, for reference:

1. Sign in at https://packagist.org with your GitHub account.
2. **Submit** → `https://github.com/korozcolt/ai-gateway`.
3. Enable the GitHub webhook (Packagist offers it after the first submit, or add it under *Settings → Webhooks* with the Packagist API token) so new tags are published automatically.
4. Check the badges in the README resolve and `composer require korozcolt/ai-gateway` works in a clean Laravel 13 project.

Versioning follows SemVer. While the major version is `0`, minor releases may include breaking changes (documented in the changelog).
