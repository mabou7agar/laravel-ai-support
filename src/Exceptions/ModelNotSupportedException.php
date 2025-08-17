<?php

declare(strict_types=1);

namespace MagicAI\LaravelAIEngine\Exceptions;

use Exception;

class ModelNotSupportedException extends Exception
{
    public function __construct(
        string $message = 'The specified AI model is not supported',
        int $code = 400,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
