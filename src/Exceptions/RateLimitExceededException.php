<?php

declare(strict_types=1);

namespace LaravelAIEngine\Exceptions;

use Exception;

class RateLimitExceededException extends Exception
{
    public function __construct(
        string $message = 'Rate limit exceeded for AI engine',
        int $code = 429,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
