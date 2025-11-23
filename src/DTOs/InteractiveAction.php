<?php

namespace LaravelAIEngine\DTOs;

use LaravelAIEngine\Enums\ActionTypeEnum;

class InteractiveAction
{
    private string $id;
    private ActionTypeEnum $type;
    private string $label;
    private ?string $description;
    private array $data;
    private array $style;
    private bool $disabled;
    private bool $loading;
    private ?string $confirmMessage;
    private ?string $successMessage;
    private ?string $errorMessage;
    private array $validation;
    private array $metadata;

    public function __construct(
        string $id,
        ActionTypeEnum $type,
        string $label,
        ?string $description = null,
        array $data = [],
        array $style = [],
        bool $disabled = false,
        bool $loading = false,
        ?string $confirmMessage = null,
        ?string $successMessage = null,
        ?string $errorMessage = null,
        array $validation = [],
        array $metadata = []
    
    ) {
        $this->id = $id;
        $this->type = $type;
        $this->label = $label;
        $this->description = $description;
        $this->data = $data;
        $this->style = $style;
        $this->disabled = $disabled;
        $this->loading = $loading;
        $this->confirmMessage = $confirmMessage;
        $this->successMessage = $successMessage;
        $this->errorMessage = $errorMessage;
        $this->validation = $validation;
        $this->metadata = $metadata;
    }

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
            $id,
            ActionTypeEnum::BUTTON,
            $label,
            $description,
            $data,
            $style
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
            $id,
            ActionTypeEnum::LINK,
            $label,
            $description,
            [
                'url' => $url,
                'external' => $external,
                'target' => $external ? '_blank' : '_self'
            ],
            $style
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
            $id,
            ActionTypeEnum::FORM,
            $label,
            $description,
            [
                'fields' => $fields,
                'method' => 'POST'
            ],
            $style,
            $validation
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
            $id,
            ActionTypeEnum::QUICK_REPLY,
            $label,
            $description,
            [
                'message' => $message,
                'auto_send' => true
            ],
            $style
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
            $id,
            ActionTypeEnum::FILE_UPLOAD,
            $label,
            $description,
            [
                'allowed_types' => $allowedTypes,
                'max_size' => $maxSize,
                'multiple' => $multiple
            ],
            $style
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
            $id,
            ActionTypeEnum::CONFIRM,
            $label,
            $description,
            $data,
            $style,
            $confirmMessage
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
            $id,
            ActionTypeEnum::MENU,
            $label,
            $description,
            [
                'options' => $options,
                'multiple' => false
            ],
            $style
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
            $id,
            ActionTypeEnum::CARD,
            $title,
            [
                'title' => $title,
                'content' => $content,
                'image_url' => $imageUrl,
                'actions' => $actions
            ],
            $style
        );
    }

    /**
     * Set action as disabled
     */
    public function disabled(bool $disabled = true): self
    {
        return new self(
            $this->id,
            $this->type,
            $this->label,
            $this->description,
            $this->data,
            $this->style,
            $disabled,
            $this->loading,
            $this->confirmMessage,
            $this->successMessage,
            $this->errorMessage,
            $this->validation,
            $this->metadata
        );
    }

    /**
     * Set action as loading
     */
    public function loading(bool $loading = true): self
    {
        return new self(
            $this->id,
            $this->type,
            $this->label,
            $this->description,
            $this->data,
            $this->style,
            $this->disabled,
            $loading,
            $this->confirmMessage,
            $this->successMessage,
            $this->errorMessage,
            $this->validation,
            $this->metadata
        );
    }

    /**
     * Add confirmation message
     */
    public function withConfirmation(string $message): self
    {
        return new self(
            $this->id,
            $this->type,
            $this->label,
            $this->description,
            $this->data,
            $this->style,
            $this->disabled,
            $this->loading,
            $message,
            $this->successMessage,
            $this->errorMessage,
            $this->validation,
            $this->metadata
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
            $data['id'],
            ActionTypeEnum::from($data['type']),
            $data['label'],
            $data['description'] ?? null,
            $data['data'] ?? [],
            $data['style'] ?? [],
            $data['disabled'] ?? false,
            $data['loading'] ?? false,
            $data['confirm_message'] ?? null,
            $data['success_message'] ?? null,
            $data['error_message'] ?? null,
            $data['validation'] ?? [],
            $data['metadata'] ?? []
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): ActionTypeEnum
    {
        return $this->type;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getStyle(): array
    {
        return $this->style;
    }

    public function getDisabled(): bool
    {
        return $this->disabled;
    }

    public function getLoading(): bool
    {
        return $this->loading;
    }

    public function getConfirmMessage(): ?string
    {
        return $this->confirmMessage;
    }

    public function getSuccessMessage(): ?string
    {
        return $this->successMessage;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getValidation(): array
    {
        return $this->validation;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Magic getter for backward compatibility
     */
    public function __get(string $name)
    {
        $getter = 'get' . ucfirst($name);
        if (method_exists($this, $getter)) {
            return $this->$getter();
        }
        if (property_exists($this, $name)) {
            return $this->$name;
        }
        throw new \InvalidArgumentException("Property {$name} does not exist");
    }
}
