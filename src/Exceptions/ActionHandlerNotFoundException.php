<?php

namespace LaravelAIEngine\Exceptions;

use Exception;

class ActionHandlerNotFoundException extends Exception
{
    public function __construct(string $message = "Action handler not found", int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
