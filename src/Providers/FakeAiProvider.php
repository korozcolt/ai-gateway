<?php

namespace Korbytes\AiGateway\Providers;

use Closure;
use Korbytes\AiGateway\Contracts\AiProviderInterface;
use Korbytes\AiGateway\Dto\AiRequest;
use Korbytes\AiGateway\Dto\AiResponse;

/** Deterministic provider for tests and local work; never touches the network. */
final class FakeAiProvider implements AiProviderInterface
{
    /** @var list<AiResponse> */
    private static array $queue = [];

    /** @var list<AiRequest> */
    private static array $requests = [];

    private static ?Closure $callback = null;

    public static function queue(AiResponse ...$responses): void
    {
        foreach ($responses as $response) {
            self::$queue[] = $response;
        }
    }

    /** @return list<AiRequest> */
    public static function requests(): array
    {
        return self::$requests;
    }

    /** Callback invoked inside complete() with the AiRequest, before the response is returned. */
    public static function onComplete(Closure $callback): void
    {
        self::$callback = $callback;
    }

    public static function reset(): void
    {
        self::$queue = [];
        self::$requests = [];
        self::$callback = null;
    }

    public function key(): string
    {
        return 'fake';
    }

    public function complete(AiRequest $request): AiResponse
    {
        self::$requests[] = $request;

        if (self::$callback !== null) {
            (self::$callback)($request);
        }

        if (self::$queue !== []) {
            return array_shift(self::$queue);
        }

        return new AiResponse(
            text: 'Gracias por compartirlo.',
            json: ['reply' => 'Gracias por compartirlo.', 'done' => false],
            inputTokens: 0,
            outputTokens: 0,
            latencyMs: 0,
            model: $request->params->model,
        );
    }
}
