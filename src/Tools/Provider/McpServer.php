<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tools\Provider;

use LaravelAIEngine\Contracts\ProviderToolInterface;

class McpServer implements ProviderToolInterface
{
    public function __construct(
        protected string $label,
        protected string $url,
        protected ?string $authorizationToken = null,
        protected array $headers = [],
        protected ?string $connectorId = null
    ) {}

    public function name(): string
    {
        return 'mcp_server';
    }

    public function authorizationToken(?string $token): self
    {
        $this->authorizationToken = $token;

        return $this;
    }

    public function headers(array $headers): self
    {
        $this->headers = $headers;

        return $this;
    }

    public function connectorId(?string $connectorId): self
    {
        $this->connectorId = $connectorId;

        return $this;
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->name(),
            'label' => $this->label,
            'url' => $this->url,
            'authorization_token' => $this->authorizationToken,
            'headers' => $this->headers,
            'connector_id' => $this->connectorId,
        ], static fn ($value): bool => $value !== null && $value !== []);
    }
}
