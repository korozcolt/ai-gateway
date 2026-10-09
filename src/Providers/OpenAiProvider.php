<?php

namespace Korbytes\AiGateway\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Korbytes\AiGateway\Contracts\ListsModels;
use Korbytes\AiGateway\Dto\AiRequest;
use Korbytes\AiGateway\Providers\Concerns\BuildsChatCompletions;

/**
 * OpenAI Chat Completions (ChatGPT models). Bearer auth; default base URL https://api.openai.com/v1.
 * Reasoning families (o1, o3, o4, gpt-5) use `max_completion_tokens` and no temperature by default;
 * a model entry in the connection can override both (`max_tokens_param`, `temperature`).
 * Structured output uses a strict json_schema response_format when the connection supports it.
 */
final class OpenAiProvider extends HttpProvider implements ListsModels
{
    use BuildsChatCompletions;

    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    private const REASONING_FAMILY = '#^(?:[\w.-]+/)?(?:o[134](?:-|$)|gpt-5)#i';

    public function key(): string
    {
        return 'openai';
    }

    protected function send(PendingRequest $http, AiRequest $request): Response
    {
        return $this->sendChatCompletion($http, $request, $this->baseUrl().'/chat/completions');
    }

    protected function defaultTokenParam(string $model): string
    {
        return $this->isReasoningModel($model) ? 'max_completion_tokens' : 'max_tokens';
    }

    protected function defaultSendsTemperature(string $model): bool
    {
        return ! $this->isReasoningModel($model);
    }

    /** @return list<string> model ids, sorted */
    public function listModels(): array
    {
        $body = $this->getJson($this->baseUrl().'/models', ['Authorization' => 'Bearer '.$this->connection->apiKey]);
        $ids = [];

        foreach ((array) ($body['data'] ?? []) as $model) {
            if (is_array($model) && is_string($model['id'] ?? null)) {
                $ids[] = $model['id'];
            }
        }

        sort($ids);

        return $ids;
    }

    private function isReasoningModel(string $model): bool
    {
        return preg_match(self::REASONING_FAMILY, $model) === 1;
    }

    private function baseUrl(): string
    {
        return rtrim($this->connection->baseUrl !== '' ? $this->connection->baseUrl : self::DEFAULT_BASE_URL, '/');
    }
}
