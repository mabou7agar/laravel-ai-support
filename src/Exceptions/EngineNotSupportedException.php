<?php

declare(strict_types=1);

namespace LaravelAIEngine\Exceptions;

use Exception;

class EngineNotSupportedException extends Exception
{
    public function __construct(
        string $message = 'The specified AI engine is not supported',
        int $code = 400,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
