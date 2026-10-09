<?php

namespace Korbytes\AiGateway\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Korbytes\AiGateway\AiFailure;
use Korbytes\AiGateway\AiFinishReason;
use Korbytes\AiGateway\Contracts\ListsModels;
use Korbytes\AiGateway\Dto\AiContentPart;
use Korbytes\AiGateway\Dto\AiMessage;
use Korbytes\AiGateway\Dto\AiRequest;
use Korbytes\AiGateway\Dto\AiUsage;
use Korbytes\AiGateway\Exceptions\AiProviderException;
use Korbytes\AiGateway\RequestOptions;

/**
 * Anthropic Messages API (Claude). Auth is the `x-api-key` header plus a fixed `anthropic-version`.
 * The system prompt travels in its own field and `max_tokens` is mandatory. Structured output is
 * obtained by forcing a single tool whose input_schema is the requested schema; the tool input is
 * returned as JSON text.
 *
 * Usage: Anthropic reports `input_tokens` WITHOUT cache hits, so inputTokens is left as reported
 * and cachedTokens is `cache_read_input_tokens` (cache creation tokens are not added).
 * Default base URL: https://api.anthropic.com/v1
 */
final class AnthropicProvider extends HttpProvider implements ListsModels
{
    public const VERSION = '2023-06-01';

    /** Anthropic temperature range is 0..1. */
    private const MAX_TEMPERATURE = 1.0;

    /** Page size and cap of pages when listing models. */
    private const MODELS_PAGE_SIZE = 100;

    private const MODELS_MAX_PAGES = 50;

    public function key(): string
    {
        return 'anthropic';
    }

    protected function send(PendingRequest $http, AiRequest $request): Response
    {
        $messages = [];

        foreach ($request->messages as $message) {
            $messages[] = [
                'role' => $message->role === 'assistant' ? 'assistant' : 'user',
                'content' => $this->content($message),
            ];
        }

        $base = [
            'model' => $request->params->model,
            'max_tokens' => $request->params->maxOutputTokens,
            'temperature' => max(0.0, min(self::MAX_TEMPERATURE, $request->params->temperature)),
            'system' => $request->system,
            'messages' => $messages,
        ];

        if ($request->jsonSchema !== null) {
            $base['tools'] = [[
                'name' => 'output',
                'description' => 'Return the structured result.',
                'input_schema' => $request->jsonSchema,
            ]];
            $base['tool_choice'] = ['type' => 'tool', 'name' => 'output'];
        }

        $body = $base + RequestOptions::sanitize($this->connection->requestOptions);

        return $http->withHeaders($this->headers())->post($this->baseUrl().'/messages', $body);
    }

    /**
     * @return string|list<array<string, mixed>>
     */
    private function content(AiMessage $message): string|array
    {
        if (! $message->isMultimodal()) {
            return $message->content;
        }

        return array_map(fn (AiContentPart $part): array => match ($part->type) {
            AiContentPart::IMAGE => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $part->mime, 'data' => $part->base64]],
            AiContentPart::FILE => ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => $part->mime ?? 'application/pdf', 'data' => $part->base64]],
            default => ['type' => 'text', 'text' => (string) $part->text],
        }, $message->content);
    }

    protected function parse(array $body): array
    {
        $blocks = $body['content'] ?? null;
        $usage = $this->usage($body);

        if (! is_array($blocks)) {
            throw new AiProviderException(AiFailure::BadResponse, usage: $usage);
        }

        $text = '';
        $toolInput = null;

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            } elseif (($block['type'] ?? null) === 'tool_use' && $toolInput === null) {
                $toolInput = $block['input'] ?? [];
            }
        }

        if ($toolInput !== null) {
            $text = (string) json_encode($toolInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $finish = match ((string) ($body['stop_reason'] ?? 'end_turn')) {
            'end_turn', 'stop_sequence', 'tool_use' => AiFinishReason::Stop,
            'max_tokens' => AiFinishReason::Length,
            'refusal' => AiFinishReason::ContentFilter,
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
        return new AiUsage(
            inputTokens: (int) data_get($body, 'usage.input_tokens', 0),
            outputTokens: (int) data_get($body, 'usage.output_tokens', 0),
            cachedTokens: (int) data_get($body, 'usage.cache_read_input_tokens', 0),
        );
    }

    /** @return list<string> */
    public function listModels(): array
    {
        $ids = [];
        $afterId = null;

        for ($page = 0; $page < self::MODELS_MAX_PAGES; $page++) {
            $query = ['limit' => self::MODELS_PAGE_SIZE] + ($afterId !== null ? ['after_id' => $afterId] : []);
            $body = $this->getJson($this->baseUrl().'/models', $this->headers(), $query);

            foreach ((array) ($body['data'] ?? []) as $model) {
                if (is_array($model) && is_string($model['id'] ?? null)) {
                    $ids[] = $model['id'];
                }
            }

            $last = $body['last_id'] ?? null;

            if (($body['has_more'] ?? false) !== true || ! is_string($last) || $last === '') {
                break;
            }

            $afterId = $last;
        }

        return $ids;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['x-api-key' => (string) $this->connection->apiKey, 'anthropic-version' => self::VERSION];
    }

    private function baseUrl(): string
    {
        return rtrim($this->connection->baseUrl !== '' ? $this->connection->baseUrl : 'https://api.anthropic.com/v1', '/');
    }
}
