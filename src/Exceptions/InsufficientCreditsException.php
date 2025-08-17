<?php

declare(strict_types=1);

namespace MagicAI\LaravelAIEngine\Exceptions;

use Exception;

class InsufficientCreditsException extends Exception
{
    public function __construct(
        string $message = 'Insufficient credits to perform this operation',
        int $code = 402,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
