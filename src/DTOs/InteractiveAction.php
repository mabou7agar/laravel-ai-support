<?php

namespace LaravelAIEngine\DTOs;

use LaravelAIEngine\Enums\ActionTypeEnum;

class InteractiveAction
{
    public function __construct(
        public readonly string $id,
        public readonly ActionTypeEnum $type,
        public readonly string $label,
        public readonly ?string $description = null,
        public readonly array $data = [],
        public readonly array $style = [],
        public readonly bool $disabled = false,
        public readonly bool $loading = false,
        public readonly ?string $confirmMessage = null,
        public readonly ?string $successMessage = null,
        public readonly ?string $errorMessage = null,
        public readonly array $validation = [],
        public readonly array $metadata = []
    ) {}

    /**
     * Create a button action
     */
    public static function button(
        string $id,
        string $label,
        array $data = [],
        ?string $description = null,
        array $style = []
    ): self {
        return new self(
            id: $id,
            type: ActionTypeEnum::BUTTON,
            label: $label,
            description: $description,
            data: $data,
            style: $style
        );
    }

    /**
     * Create a link action
     */
    public static function link(
        string $id,
        string $label,
        string $url,
        ?string $description = null,
        bool $external = false,
        array $style = []
    ): self {
        return new self(
            id: $id,
            type: ActionTypeEnum::LINK,
            label: $label,
            description: $description,
            data: [
                'url' => $url,
                'external' => $external,
                'target' => $external ? '_blank' : '_self'
            ],
            style: $style
        );
    }

    /**
     * Create a form action
     */
    public static function form(
        string $id,
        string $label,
        array $fields,
        ?string $description = null,
        array $validation = [],
        array $style = []
    ): self {
        return new self(
            id: $id,
            type: ActionTypeEnum::FORM,
            label: $label,
            description: $description,
            data: [
                'fields' => $fields,
                'method' => 'POST'
            ],
            style: $style,
            validation: $validation
        );
    }

    /**
     * Create a quick reply action
     */
    public static function quickReply(
        string $id,
        string $label,
        string $message,
        ?string $description = null,
        array $style = []
    ): self {
        return new self(
            id: $id,
            type: ActionTypeEnum::QUICK_REPLY,
            label: $label,
            description: $description,
            data: [
                'message' => $message,
                'auto_send' => true
            ],
            style: $style
        );
    }

    /**
     * Create a file upload action
     */
    public static function fileUpload(
        string $id,
        string $label,
        array $allowedTypes = [],
        int $maxSize = 10485760, // 10MB
        bool $multiple = false,
        ?string $description = null,
        array $style = []
    ): self {
        return new self(
            id: $id,
            type: ActionTypeEnum::FILE_UPLOAD,
            label: $label,
            description: $description,
            data: [
                'allowed_types' => $allowedTypes,
                'max_size' => $maxSize,
                'multiple' => $multiple
            ],
            style: $style
        );
    }

    /**
     * Create a confirmation action
     */
    public static function confirm(
        string $id,
        string $label,
        string $confirmMessage,
        array $data = [],
        ?string $description = null,
        array $style = []
    ): self {
        return new self(
            id: $id,
            type: ActionTypeEnum::CONFIRM,
            label: $label,
            description: $description,
            data: $data,
            style: $style,
            confirmMessage: $confirmMessage
        );
    }

    /**
     * Create a menu/dropdown action
     */
    public static function menu(
        string $id,
        string $label,
        array $options,
        ?string $description = null,
        array $style = []
    ): self {
        return new self(
            id: $id,
            type: ActionTypeEnum::MENU,
            label: $label,
            description: $description,
            data: [
                'options' => $options,
                'multiple' => false
            ],
            style: $style
        );
    }

    /**
     * Create a card action
     */
    public static function card(
        string $id,
        string $title,
        string $content,
        ?string $imageUrl = null,
        array $actions = [],
        array $style = []
    ): self {
        return new self(
            id: $id,
            type: ActionTypeEnum::CARD,
            label: $title,
            data: [
                'title' => $title,
                'content' => $content,
                'image_url' => $imageUrl,
                'actions' => $actions
            ],
            style: $style
        );
    }

    /**
     * Set action as disabled
     */
    public function disabled(bool $disabled = true): self
    {
        return new self(
            id: $this->id,
            type: $this->type,
            label: $this->label,
            description: $this->description,
            data: $this->data,
            style: $this->style,
            disabled: $disabled,
            loading: $this->loading,
            confirmMessage: $this->confirmMessage,
            successMessage: $this->successMessage,
            errorMessage: $this->errorMessage,
            validation: $this->validation,
            metadata: $this->metadata
        );
    }

    /**
     * Set action as loading
     */
    public function loading(bool $loading = true): self
    {
        return new self(
            id: $this->id,
            type: $this->type,
            label: $this->label,
            description: $this->description,
            data: $this->data,
            style: $this->style,
            disabled: $this->disabled,
            loading: $loading,
            confirmMessage: $this->confirmMessage,
            successMessage: $this->successMessage,
            errorMessage: $this->errorMessage,
            validation: $this->validation,
            metadata: $this->metadata
        );
    }

    /**
     * Add confirmation message
     */
    public function withConfirmation(string $message): self
    {
        return new self(
            id: $this->id,
            type: $this->type,
            label: $this->label,
            description: $this->description,
            data: $this->data,
            style: $this->style,
            disabled: $this->disabled,
            loading: $this->loading,
            confirmMessage: $message,
            successMessage: $this->successMessage,
            errorMessage: $this->errorMessage,
            validation: $this->validation,
            metadata: $this->metadata
        );
    }

    /**
     * Convert to array for JSON serialization
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'label' => $this->label,
            'description' => $this->description,
            'data' => $this->data,
            'style' => $this->style,
            'disabled' => $this->disabled,
            'loading' => $this->loading,
            'confirm_message' => $this->confirmMessage,
            'success_message' => $this->successMessage,
            'error_message' => $this->errorMessage,
            'validation' => $this->validation,
            'metadata' => $this->metadata
        ];
    }

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            type: ActionTypeEnum::from($data['type']),
            label: $data['label'],
            description: $data['description'] ?? null,
            data: $data['data'] ?? [],
            style: $data['style'] ?? [],
            disabled: $data['disabled'] ?? false,
            loading: $data['loading'] ?? false,
            confirmMessage: $data['confirm_message'] ?? null,
            successMessage: $data['success_message'] ?? null,
            errorMessage: $data['error_message'] ?? null,
            validation: $data['validation'] ?? [],
            metadata: $data['metadata'] ?? []
        );
    }
}
