<?php

namespace Korbytes\AiGateway\Dto;

/**
 * One piece of a multimodal message: text, an inline image or an inline file (PDF...), both as base64.
 * Providers translate it to their wire format. Use the named constructors.
 */
final readonly class AiContentPart
{
    public const TEXT = 'text';

    public const IMAGE = 'image';

    public const FILE = 'file';

    private function __construct(
        public string $type,
        public ?string $text = null,
        #[\SensitiveParameter] public ?string $base64 = null,
        public ?string $mime = null,
        public ?string $filename = null,
    ) {}

    public static function text(string $text): self
    {
        return new self(self::TEXT, text: $text);
    }

    public static function image(#[\SensitiveParameter] string $base64, string $mime = 'image/jpeg'): self
    {
        return new self(self::IMAGE, base64: $base64, mime: $mime);
    }

    public static function file(#[\SensitiveParameter] string $base64, string $mime = 'application/pdf', string $filename = 'document.pdf'): self
    {
        return new self(self::FILE, base64: $base64, mime: $mime, filename: $filename);
    }

    public function dataUri(): string
    {
        return 'data:'.$this->mime.';base64,'.$this->base64;
    }

    /** @return array<string, int|string|null> */
    public function __debugInfo(): array
    {
        return ['type' => $this->type, 'mime' => $this->mime, 'bytes' => $this->base64 === null ? 0 : strlen($this->base64)];
    }
}
