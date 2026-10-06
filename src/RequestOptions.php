<?php

namespace Korbytes\AiGateway;

/**
 * Provider-specific extra fields a connection adds to the request body (for example OpenRouter's
 * {"provider":{"zdr":true}}). Fields the app controls itself, and anything auth related, can never
 * be set from here: the denylist is enforced at save time (validation) and again in the driver.
 */
final class RequestOptions
{
    /** Top-level body keys that connection options may never set (compared case-insensitively). */
    public const DENIED_KEYS = [
        'model', 'messages', 'stream', 'stream_options', 'max_tokens', 'max_completion_tokens', 'temperature',
        'response_format', 'tools', 'tool_choice', 'functions', 'function_call',
        'authorization', 'proxy-authorization', 'headers', 'header', 'api_key', 'apikey', 'api-key',
        'x-api-key', 'x-goog-api-key', 'token', 'access_token', 'bearer', 'key', 'secret', 'cookie',
    ];

    /**
     * @param  array<mixed>  $options
     * @return list<string> denied top-level keys present
     */
    public static function deniedKeys(array $options): array
    {
        $found = [];

        foreach (array_keys($options) as $key) {
            if (in_array(strtolower((string) $key), self::DENIED_KEYS, true)) {
                $found[] = (string) $key;
            }
        }

        return $found;
    }

    /**
     * @param  array<mixed>  $options
     * @return array<string, mixed> options without denied or non-string keys
     */
    public static function sanitize(array $options): array
    {
        $clean = [];

        foreach ($options as $key => $value) {
            if (! is_string($key) || in_array(strtolower($key), self::DENIED_KEYS, true)) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
