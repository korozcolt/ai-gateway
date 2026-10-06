<?php

namespace Korbytes\AiGateway\Dto;

final readonly class AiMessage
{
    /**
     * @param  string  $role  'user' or 'assistant'
     * @param  string|list<AiContentPart>  $content  plain text, or content parts for multimodal input (images, PDFs)
     */
    public function __construct(public string $role, public string|array $content) {}

    public function isMultimodal(): bool
    {
        return is_array($this->content);
    }

    /** Text of the message; for content parts, the text parts joined. */
    public function plainText(): string
    {
        if (is_string($this->content)) {
            return $this->content;
        }

        return implode("\n", array_map(
            fn (AiContentPart $part): string => (string) $part->text,
            array_filter($this->content, fn (AiContentPart $part): bool => $part->type === AiContentPart::TEXT),
        ));
    }
}
