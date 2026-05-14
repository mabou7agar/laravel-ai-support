<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class CollectorToolResult
{
    public function __construct(
        public string $tool,
        public array $arguments,
        public bool $success,
        public mixed $result = null,
        public ?string $error = null,
        public ?bool $domainSuccess = null,
    ) {
    }

    public function toArray(): array
    {
        $payload = [
            'tool' => $this->tool,
            'arguments' => $this->arguments,
            'success' => $this->success,
        ];

        if ($this->result !== null) {
            $payload['result'] = $this->result;
        }

        if ($this->error !== null) {
            $payload['error'] = $this->error;
        }

        if ($this->domainSuccess !== null) {
            $payload['domain_success'] = $this->domainSuccess;
        }

        return $payload;
    }
}
