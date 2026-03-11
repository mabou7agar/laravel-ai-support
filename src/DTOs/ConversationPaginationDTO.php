<?php

namespace LaravelAIEngine\DTOs;

class ConversationPaginationDTO
{
    public function __construct(
        public readonly int $total,
        public readonly int $perPage,
        public readonly int $currentPage,
        public readonly int $lastPage,
        public readonly int $from,
        public readonly int $to,
    ) {}

    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'per_page' => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}
