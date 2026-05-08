<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

use JsonSerializable;

class AgentCapabilityDocument implements JsonSerializable
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $text,
        public readonly array $payload = [],
        public readonly array $metadata = []
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            text: (string) ($data['text'] ?? ''),
            payload: (array) ($data['payload'] ?? []),
            metadata: (array) ($data['metadata'] ?? [])
        );
    }

    /**
     * @return array{id:string,text:string,payload:array<string,mixed>,metadata:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'payload' => $this->payload,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @return array{id:string,text:string,payload:array<string,mixed>,metadata:array<string,mixed>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
