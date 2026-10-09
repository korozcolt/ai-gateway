<?php

namespace Korbytes\AiGateway\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Korbytes\AiGateway\Dto\AiRequest;
use Korbytes\AiGateway\Providers\Concerns\BuildsChatCompletions;

/**
 * OpenAI-compatible chat/completions (NVIDIA Build, OpenRouter, Azure v1, Mistral, Groq...).
 * Auth is a Bearer key. Structured output uses response_format json_schema only when the
 * connection declares it supports it; otherwise the schema is requested in the system prompt.
 * Sends `max_tokens` and `temperature` unless a model entry declares otherwise.
 */
final class OpenAiCompatibleProvider extends HttpProvider
{
    use BuildsChatCompletions;

    public function key(): string
    {
        return 'openai_compatible';
    }

    protected function send(PendingRequest $http, AiRequest $request): Response
    {
        return $this->sendChatCompletion($http, $request, rtrim($this->connection->baseUrl, '/').'/chat/completions');
    }

    protected function defaultTokenParam(string $model): string
    {
        return 'max_tokens';
    }

    protected function defaultSendsTemperature(string $model): bool
    {
        return true;
    }
}
