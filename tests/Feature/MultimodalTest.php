<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Korbytes\AiGateway\AiConnection;
use Korbytes\AiGateway\Dto\AiContentPart;
use Korbytes\AiGateway\Dto\AiMessage;
use Korbytes\AiGateway\Dto\AiParams;
use Korbytes\AiGateway\Dto\AiRequest;
use Korbytes\AiGateway\Providers\GeminiProvider;
use Korbytes\AiGateway\Providers\OpenAiCompatibleProvider;

const MM_IMAGE = 'aW1hZ2UtYnl0ZXM=';
const MM_PDF = 'cGRmLWJ5dGVz';

function multimodalRequest(): AiRequest
{
    return new AiRequest(
        'Extract the fields.',
        [new AiMessage('user', [
            AiContentPart::text('Cédula del compareciente'),
            AiContentPart::image(MM_IMAGE, 'image/png'),
            AiContentPart::file(MM_PDF, 'application/pdf', 'certificado.pdf'),
        ])],
        new AiParams('vision-model', 0.1, 500, 30),
    );
}

beforeEach(fn () => Http::preventStrayRequests());

it('sends images and PDFs to OpenAI-compatible providers (OpenRouter format) as data URIs', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']], 'usage' => []])]);
    $provider = new OpenAiCompatibleProvider(new AiConnection('AIP-0009', 'openai_compatible', 'https://openrouter.ai/api/v1', 'k', false));

    $provider->complete(multimodalRequest());

    Http::assertSent(function (Request $request) {
        $content = $request->data()['messages'][1]['content'];

        return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $content[0] === ['type' => 'text', 'text' => 'Cédula del compareciente']
            && $content[1] === ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.MM_IMAGE]]
            && $content[2] === ['type' => 'file', 'file' => ['filename' => 'certificado.pdf', 'file_data' => 'data:application/pdf;base64,'.MM_PDF]];
    });
});

it('keeps plain text messages as plain strings', function () {
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']], 'usage' => []])]);
    $provider = new OpenAiCompatibleProvider(new AiConnection('AIP-0009', 'openai_compatible', 'https://openrouter.ai/api/v1', 'k', false));

    $provider->complete(new AiRequest('s', [new AiMessage('user', 'hola')], new AiParams('m', 0.1, 10, 5)));

    Http::assertSent(fn (Request $request) => $request->data()['messages'][1] === ['role' => 'user', 'content' => 'hola']);
});

it('sends images and files to Gemini as inlineData parts', function () {
    Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']], 'usageMetadata' => []])]);
    $provider = new GeminiProvider(new AiConnection('AIP-0010', 'gemini', 'https://generativelanguage.googleapis.com/v1beta', 'k', true));

    $provider->complete(multimodalRequest());

    Http::assertSent(function (Request $request) {
        $parts = $request->data()['contents'][0]['parts'];

        return $parts[0] === ['text' => 'Cédula del compareciente']
            && $parts[1] === ['inlineData' => ['mimeType' => 'image/png', 'data' => MM_IMAGE]]
            && $parts[2] === ['inlineData' => ['mimeType' => 'application/pdf', 'data' => MM_PDF]];
    });
});

it('never exposes the binary content when a part is dumped', function () {
    $part = AiContentPart::image(MM_IMAGE, 'image/png');

    expect(print_r($part->__debugInfo(), true))->not->toContain(MM_IMAGE)
        ->and((new AiMessage('user', [AiContentPart::text('a'), $part, AiContentPart::text('b')]))->plainText())->toBe("a\nb");
});
