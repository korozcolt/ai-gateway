<?php

namespace Korbytes\AiGateway\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Korbytes\AiGateway\AiFailure;
use Korbytes\AiGateway\AiFinishReason;
use Korbytes\AiGateway\Dto\AiContentPart;
use Korbytes\AiGateway\Dto\AiMessage;
use Korbytes\AiGateway\Dto\AiRequest;
use Korbytes\AiGateway\Dto\AiUsage;
use Korbytes\AiGateway\Exceptions\AiProviderException;

/**
 * Google Gemini native generateContent (see 03-SPIKE-provider-formats.md). No tools, grounding or
 * cachedContent are sent. The key travels only in the x-goog-api-key header.
 */
final class GeminiProvider extends HttpProvider
{
    public function key(): string
    {
        return 'gemini';
    }

    protected function send(PendingRequest $http, AiRequest $request): Response
    {
        $generation = [
            'temperature' => $request->params->temperature,
            'maxOutputTokens' => $request->params->maxOutputTokens,
        ];

        if ($request->jsonSchema !== null) {
            $generation['responseMimeType'] = 'application/json';
            $generation['responseJsonSchema'] = $request->jsonSchema;
        }

        $url = rtrim($this->connection->baseUrl, '/').'/models/'.$request->params->model.':generateContent';

        return $http->withHeaders(['x-goog-api-key' => (string) $this->connection->apiKey])->post($url, [
            'systemInstruction' => ['parts' => [['text' => $request->system]]],
            'contents' => array_map(fn (AiMessage $message) => [
                'role' => $message->role === 'assistant' ? 'model' : 'user',
                'parts' => $this->parts($message),
            ], $request->messages),
            'generationConfig' => $generation,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function parts(AiMessage $message): array
    {
        if (! $message->isMultimodal()) {
            return [['text' => $message->content]];
        }

        return array_map(fn (AiContentPart $part): array => $part->type === AiContentPart::TEXT
            ? ['text' => (string) $part->text]
            : ['inlineData' => ['mimeType' => $part->mime, 'data' => $part->base64]], $message->content);
    }

    protected function parse(array $body): array
    {
        $usage = $this->usage($body);

        if (data_get($body, 'promptFeedback.blockReason') !== null && data_get($body, 'candidates.0') === null) {
            throw new AiProviderException(AiFailure::ContentFiltered, usage: $usage);
        }

        $candidate = data_get($body, 'candidates.0');

        if (! is_array($candidate)) {
            throw new AiProviderException(AiFailure::BadResponse, usage: $usage);
        }

        $text = '';

        foreach ((array) data_get($candidate, 'content.parts', []) as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text']) && ($part['thought'] ?? false) !== true) {
                $text .= $part['text'];
            }
        }

        $finish = $this->finishReason((string) ($candidate['finishReason'] ?? 'STOP'));

        if ($text === '' && $finish === AiFinishReason::ContentFilter) {
            throw new AiProviderException(AiFailure::ContentFiltered, usage: $usage);
        }

        return [$text, $usage, $finish];
    }

    /** @param  array<string, mixed>|null  $body */
    protected function usageOnFailure(?array $body): ?AiUsage
    {
        return $body === null ? null : $this->usage($body, nullWhenEmpty: true);
    }

    /** @param array<string, mixed> $body */
    private function usage(array $body, bool $nullWhenEmpty = false): ?AiUsage
    {
        $meta = $body['usageMetadata'] ?? null;

        if (! is_array($meta)) {
            return $nullWhenEmpty ? null : new AiUsage;
        }

        // thoughtsTokenCount is separate from candidatesTokenCount (totalTokenCount = prompt + thoughts + candidates).
        return new AiUsage(
            inputTokens: (int) ($meta['promptTokenCount'] ?? 0),
            outputTokens: (int) ($meta['candidatesTokenCount'] ?? 0),
            thinkingTokens: (int) ($meta['thoughtsTokenCount'] ?? 0),
            cachedTokens: (int) ($meta['cachedContentTokenCount'] ?? 0),
        );
    }

    private function finishReason(string $reason): AiFinishReason
    {
        return match ($reason) {
            'STOP' => AiFinishReason::Stop,
            'MAX_TOKENS' => AiFinishReason::Length,
            'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'LANGUAGE' => AiFinishReason::ContentFilter,
            default => AiFinishReason::Other,
        };
    }
}
