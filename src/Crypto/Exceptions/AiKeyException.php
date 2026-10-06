<?php

namespace Korbytes\AiGateway\Crypto\Exceptions;

use RuntimeException;

/** Messages must never include key material. */
class AiKeyException extends RuntimeException {}
