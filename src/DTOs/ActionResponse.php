<?php

namespace LaravelAIEngine\DTOs;

use LaravelAIEngine\Enums\ActionTypeEnum;

class ActionResponse
{
    public function __construct(
        public readonly string $actionId,
        public readonly ActionTypeEnum $actionType,
        public readonly bool $success,
        public readonly ?string $message = null,
        public readonly array $data = [],
        public readonly array $errors = [],
        public readonly ?string $redirectUrl = null,
        public readonly bool $closeModal = false,
        public readonly bool $refreshPage = false,
        public readonly ?string $nextAction = null,
        public readonly array $metadata = []
    ) {}

    /**
     * Create a successful action response
     */
    public static function success(
        string $actionId,
        ActionTypeEnum $actionType,
        ?string $message = null,
        array $data = [],
        array $metadata = []
    ): self {
        return new self(
            actionId: $actionId,
            actionType: $actionType,
            success: true,
            message: $message,
            data: $data,
            metadata: $metadata
        );
    }

    /**
     * Create a failed action response
     */
    public static function error(
        string $actionId,
        ActionTypeEnum $actionType,
        string $message,
        array $errors = [],
        array $metadata = []
    ): self {
        return new self(
            actionId: $actionId,
            actionType: $actionType,
            success: false,
            message: $message,
            errors: $errors,
            metadata: $metadata
        );
    }

    /**
     * Create a redirect response
     */
    public static function redirect(
        string $actionId,
        ActionTypeEnum $actionType,
        string $url,
        ?string $message = null,
        array $data = []
    ): self {
        return new self(
            actionId: $actionId,
            actionType: $actionType,
            success: true,
            message: $message,
            data: $data,
            redirectUrl: $url
        );
    }

    /**
     * Create a modal close response
     */
    public static function closeModal(
        string $actionId,
        ActionTypeEnum $actionType,
        ?string $message = null,
        array $data = []
    ): self {
        return new self(
            actionId: $actionId,
            actionType: $actionType,
            success: true,
            message: $message,
            data: $data,
            closeModal: true
        );
    }

    /**
     * Create a page refresh response
     */
    public static function refresh(
        string $actionId,
        ActionTypeEnum $actionType,
        ?string $message = null,
        array $data = []
    ): self {
        return new self(
            actionId: $actionId,
            actionType: $actionType,
            success: true,
            message: $message,
            data: $data,
            refreshPage: true
        );
    }

    /**
     * Create a chained action response
     */
    public static function nextAction(
        string $actionId,
        ActionTypeEnum $actionType,
        string $nextActionId,
        ?string $message = null,
        array $data = []
    ): self {
        return new self(
            actionId: $actionId,
            actionType: $actionType,
            success: true,
            message: $message,
            data: $data,
            nextAction: $nextActionId
        );
    }

    /**
     * Add redirect URL
     */
    public function withRedirect(string $url): self
    {
        return new self(
            actionId: $this->actionId,
            actionType: $this->actionType,
            success: $this->success,
            message: $this->message,
            data: $this->data,
            errors: $this->errors,
            redirectUrl: $url,
            closeModal: $this->closeModal,
            refreshPage: $this->refreshPage,
            nextAction: $this->nextAction,
            metadata: $this->metadata
        );
    }

    /**
     * Set to close modal
     */
    public function withCloseModal(): self
    {
        return new self(
            actionId: $this->actionId,
            actionType: $this->actionType,
            success: $this->success,
            message: $this->message,
            data: $this->data,
            errors: $this->errors,
            redirectUrl: $this->redirectUrl,
            closeModal: true,
            refreshPage: $this->refreshPage,
            nextAction: $this->nextAction,
            metadata: $this->metadata
        );
    }

    /**
     * Set to refresh page
     */
    public function withRefresh(): self
    {
        return new self(
            actionId: $this->actionId,
            actionType: $this->actionType,
            success: $this->success,
            message: $this->message,
            data: $this->data,
            errors: $this->errors,
            redirectUrl: $this->redirectUrl,
            closeModal: $this->closeModal,
            refreshPage: true,
            nextAction: $this->nextAction,
            metadata: $this->metadata
        );
    }

    /**
     * Convert to array for JSON serialization
     */
    public function toArray(): array
    {
        return [
            'action_id' => $this->actionId,
            'action_type' => $this->actionType->value,
            'success' => $this->success,
            'message' => $this->message,
            'data' => $this->data,
            'errors' => $this->errors,
            'redirect_url' => $this->redirectUrl,
            'close_modal' => $this->closeModal,
            'refresh_page' => $this->refreshPage,
            'next_action' => $this->nextAction,
            'metadata' => $this->metadata
        ];
    }

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            actionId: $data['action_id'],
            actionType: ActionTypeEnum::from($data['action_type']),
            success: $data['success'],
            message: $data['message'] ?? null,
            data: $data['data'] ?? [],
            errors: $data['errors'] ?? [],
            redirectUrl: $data['redirect_url'] ?? null,
            closeModal: $data['close_modal'] ?? false,
            refreshPage: $data['refresh_page'] ?? false,
            nextAction: $data['next_action'] ?? null,
            metadata: $data['metadata'] ?? []
        );
    }
}
