<?php

namespace Korbytes\AiGateway\Crypto;

use Illuminate\Support\Str;

final class AiKeyId
{
    /** System-generated key id: never typed by hand. */
    public static function generate(): string
    {
        return strtolower((string) Str::ulid());
    }
}
