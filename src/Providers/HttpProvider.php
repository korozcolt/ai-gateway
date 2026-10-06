<?php

namespace Korbytes\AiGateway\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Korbytes\AiGateway\AiConnection;
use Korbytes\AiGateway\AiFailure;
use Korbytes\AiGateway\AiFinishReason;
use Korbytes\AiGateway\Contracts\AiProviderInterface;
use Korbytes\AiGateway\Dto\AiRequest;
use Korbytes\AiGateway\Dto\AiResponse;
use Korbytes\AiGateway\Dto\AiUsage;
use Korbytes\AiGateway\Exceptions\AiProviderException;

/**
 * Shared plumbing of the HTTP drivers: timing, error classification (reason code only) and
 * JSON decoding. Never logs, never touches DB/cache/storage, and no exception carries prompt,
 * response or key text.
 */
abstract class HttpProvider implements AiProviderInterface
{
    /** Unit: nanoseconds per millisecond, for the monotonic clock. */
    private const NS_PER_MS = 1000000;

    /** HTTP status class boundary: 5xx are server errors. */
    private const SERVER_ERROR_FROM = 500;

    public function __construct(protected readonly AiConnection $connection) {}

    final public function complete(AiRequest $request): AiResponse
    {
        if (blank($this->connection->apiKey)) {
            throw new AiProviderException(AiFailure::MissingCredentials);
        }

        $started = hrtime(true);

        try {
            $response = $this->send(Http::timeout($request->params->timeoutSeconds)->acceptJson(), $request);
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), 'cURL error 28');

            throw new AiProviderException($timedOut ? AiFailure::Timeout : AiFailure::Connection);
        }

        $latencyMs = intdiv(hrtime(true) - $started, self::NS_PER_MS);
        $body = $response->json();
        $body = is_array($body) ? $body : null;

        if ($response->status() !== 200) {
            throw new AiProviderException($this->classify($response->status(), $body), usage: $this->usageOnFailure($body));
        }

        if ($body === null) {
            throw new AiProviderException(AiFailure::BadResponse);
        }

        [$text, $usage, $finish] = $this->parse($body);

        return new AiResponse(
            text: $text,
            json: $request->jsonSchema !== null ? $this->decodeJson($text) : null,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            latencyMs: $latencyMs,
            model: $request->params->model,
            thinkingTokens: $usage->thinkingTokens,
            cachedTokens: $usage->cachedTokens,
            finishReason: $finish,
            reportedCostUsd: $usage->reportedCostUsd,
        );
    }

    /**
     * Failure for a non-200 status. Subclasses refine with vendor-specific markers.
     *
     * @param  array<string, mixed>|null  $body
     */
    protected function classify(int $status, ?array $body): AiFailure
    {
        return match (true) {
            in_array($status, [401, 402, 403], true) => AiFailure::Auth,
            $status === 429 => AiFailure::RateLimit,
            in_array($status, [202, 408, 504], true) => AiFailure::Timeout,
            in_array($status, [400, 404, 413, 416, 422], true) => AiFailure::InvalidRequest,
            $status >= self::SERVER_ERROR_FROM => AiFailure::ServerError,
            $status >= 400 => AiFailure::InvalidRequest,
            default => AiFailure::BadResponse,
        };
    }

    /**
     * Tokens an error body reports (providers that bill on blocks); null when none.
     *
     * @param  array<string, mixed>|null  $body
     */
    protected function usageOnFailure(?array $body): ?AiUsage
    {
        return null;
    }

    abstract protected function send(PendingRequest $http, AiRequest $request): Response;

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: string, 1: AiUsage, 2: AiFinishReason} text, usage, finish reason
     *
     * @throws AiProviderException
     */
    abstract protected function parse(array $body): array;

    /** @return array<string, mixed>|null */
    private function decodeJson(string $text): ?array
    {
        $trimmed = trim($text);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $trimmed, $matches) === 1) {
            $trimmed = $matches[1];
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : null;
    }
}
