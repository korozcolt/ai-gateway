<?php

namespace Korbytes\AiGateway\Providers\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Korbytes\AiGateway\AiFailure;
use Korbytes\AiGateway\AiFinishReason;
use Korbytes\AiGateway\Dto\AiContentPart;
use Korbytes\AiGateway\Dto\AiMessage;
use Korbytes\AiGateway\Dto\AiRequest;
use Korbytes\AiGateway\Dto\AiUsage;
use Korbytes\AiGateway\Exceptions\AiProviderException;
use Korbytes\AiGateway\RequestOptions;

/**
 * Shared chat/completions wire logic of the OpenAI-style drivers (openai_compatible and openai).
 * Using classes extend HttpProvider and decide, per model, the default token parameter and whether
 * a temperature is sent; a model entry in the connection can always override both.
 */
trait BuildsChatCompletions
{
    /** Name of the token-limit field for a model when the connection does not declare one. */
    abstract protected function defaultTokenParam(string $model): string;

    /** Whether a temperature is sent for a model when the connection does not declare it. */
    abstract protected function defaultSendsTemperature(string $model): bool;

    protected function sendChatCompletion(PendingRequest $http, AiRequest $request, string $url): Response
    {
        $system = $request->system;

        if ($request->jsonSchema !== null && ! $this->connection->supportsJsonSchema) {
            $system .= "\n\nRespond with a single JSON object only, no prose and no code fences, that conforms to this JSON Schema:\n"
                .json_encode($request->jsonSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $messages = [['role' => 'system', 'content' => $system]];

        foreach ($request->messages as $message) {
            $messages[] = ['role' => $message->role, 'content' => $this->content($message)];
        }

        // Reasoning models (OpenAI o-series, gpt-5...) reject `max_tokens` and a custom temperature: a model entry can
        // declare {"max_tokens_param": "max_completion_tokens", "temperature": false} to adapt the request.
        $model = $request->params->model;
        $modelOptions = $this->connection->modelOptions[$model] ?? [];
        $tokenParam = isset($modelOptions['max_tokens_param'])
            ? ($modelOptions['max_tokens_param'] === 'max_completion_tokens' ? 'max_completion_tokens' : 'max_tokens')
            : $this->defaultTokenParam($model);
        $sendsTemperature = array_key_exists('temperature', $modelOptions)
            ? $modelOptions['temperature'] !== false
            : $this->defaultSendsTemperature($model);

        $base = [
            'model' => $model,
            'messages' => $messages,
            $tokenParam => $request->params->maxOutputTokens,
        ];

        if ($sendsTemperature) {
            $base['temperature'] = $request->params->temperature;
        }

        if ($request->jsonSchema !== null && $this->connection->supportsJsonSchema) {
            $base['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'output', 'strict' => true, 'schema' => $request->jsonSchema],
            ];
        }

        // The app's fields come first so they always win on a key collision; the denylist removes them from options anyway.
        $body = $base + RequestOptions::sanitize($this->connection->requestOptions);

        return $http->withToken((string) $this->connection->apiKey)->post($url, $body);
    }

    /**
     * Plain text stays a string; multimodal messages become OpenAI-style content parts
     * (text, image_url data URI, file with data URI: the format OpenRouter also accepts for PDFs).
     *
     * @return string|list<array<string, mixed>>
     */
    private function content(AiMessage $message): string|array
    {
        if (! $message->isMultimodal()) {
            return $message->content;
        }

        return array_map(fn (AiContentPart $part): array => match ($part->type) {
            AiContentPart::IMAGE => ['type' => 'image_url', 'image_url' => ['url' => $part->dataUri()]],
            AiContentPart::FILE => ['type' => 'file', 'file' => ['filename' => $part->filename, 'file_data' => $part->dataUri()]],
            default => ['type' => 'text', 'text' => (string) $part->text],
        }, $message->content);
    }

    /** @param array<string, mixed>|null $body */
    protected function classify(int $status, ?array $body): AiFailure
    {
        if ($status === 400 && data_get($body, 'error.code') === 'content_filter') {
            return AiFailure::ContentFiltered;
        }

        return parent::classify($status, $body);
    }

    protected function parse(array $body): array
    {
        $choice = data_get($body, 'choices.0');
        $usage = $this->usage($body);

        if (! is_array($choice)) {
            throw new AiProviderException(AiFailure::BadResponse, usage: $usage);
        }

        $content = data_get($choice, 'message.content');
        $text = is_string($content) ? $content : '';
        $finish = match ((string) ($choice['finish_reason'] ?? 'stop')) {
            'stop' => AiFinishReason::Stop,
            'length' => AiFinishReason::Length,
            'content_filter' => AiFinishReason::ContentFilter,
            default => AiFinishReason::Other,
        };

        if ($text === '' && $finish === AiFinishReason::ContentFilter) {
            throw new AiProviderException(AiFailure::ContentFiltered, usage: $usage);
        }

        return [$text, $usage, $finish];
    }

    /** @param array<string, mixed> $body */
    private function usage(array $body): AiUsage
    {
        $completion = (int) data_get($body, 'usage.completion_tokens', 0);
        // completion_tokens includes reasoning tokens: split them so output + thinking = completion.
        $reasoning = min($completion, (int) data_get($body, 'usage.completion_tokens_details.reasoning_tokens', 0));

        return new AiUsage(
            inputTokens: (int) data_get($body, 'usage.prompt_tokens', 0),
            outputTokens: $completion - $reasoning,
            thinkingTokens: $reasoning,
            cachedTokens: (int) data_get($body, 'usage.prompt_tokens_details.cached_tokens', 0),
            reportedCostUsd: is_numeric($cost = data_get($body, 'usage.cost')) ? (float) $cost : null,
        );
    }
}
