<?php

namespace LaravelAIEngine\DTOs;

class ConversationListResponseDTO
{
    /**
     * @param array<int, ConversationSessionPreviewDTO> $conversations
     */
    public function __construct(
        public readonly array $conversations,
        public readonly ConversationPaginationDTO $pagination,
    ) {}

    public function toArray(): array
    {
        return [
            'conversations' => array_map(
                static fn (ConversationSessionPreviewDTO $conversation): array => $conversation->toArray(),
                $this->conversations
            ),
            'pagination' => $this->pagination->toArray(),
        ];
    }
}
