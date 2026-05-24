<?php

declare(strict_types=1);

namespace LaravelAIEngine\Exceptions;

class LangGraphRuntimeException extends \RuntimeException
{
    public function __construct(
        protected string $reason = 'langgraph_runtime_error',
        protected ?int $statusCode = null,
        ?\Throwable $previous = null
    ) {
        $message = $statusCode === null
            ? 'LangGraph runtime request failed.'
            : "LangGraph runtime request failed with HTTP {$statusCode}.";

        parent::__construct($message, 0, $previous);
    }

    public static function http(int $statusCode): self
    {
        return new self('langgraph_http_error', $statusCode);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }
}
