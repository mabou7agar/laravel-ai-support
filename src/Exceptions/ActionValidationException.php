<?php

namespace LaravelAIEngine\Exceptions;

use Exception;

class ActionValidationException extends Exception
{
    protected array $errors;

    public function __construct(string $message = "Action validation failed", array $errors = [], int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
