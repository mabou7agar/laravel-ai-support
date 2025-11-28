<?php

namespace LaravelAIEngine\DTOs;

use LaravelAIEngine\Enums\ActionTypeEnum;

class ActionResponse
{
    private string $actionId;
    private ActionTypeEnum $actionType;
    private bool $success;
    private ?string $message;
    private array $data;
    private array $errors;
    private ?string $redirectUrl;
    private bool $closeModal;
    private bool $refreshPage;
    private ?string $nextAction;
    private array $metadata;

    public function __construct(
        string $actionId,
        ActionTypeEnum $actionType,
        bool $success,
        ?string $message = null,
        array $data = [],
        array $errors = [],
        ?string $redirectUrl = null,
        bool $closeModal = false,
        bool $refreshPage = false,
        ?string $nextAction = null,
        array $metadata = []
    
    ) {
        $this->actionId = $actionId;
        $this->actionType = $actionType;
        $this->success = $success;
        $this->message = $message;
        $this->data = $data;
        $this->errors = $errors;
        $this->redirectUrl = $redirectUrl;
        $this->closeModal = $closeModal;
        $this->refreshPage = $refreshPage;
        $this->nextAction = $nextAction;
        $this->metadata = $metadata;
    }

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
            $actionId,
            $actionType,
            true,
            $message,
            $data,
            $metadata
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
            $actionId,
            $actionType,
            false,
            $message,
            $errors,
            $metadata
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
            $actionId,
            $actionType,
            true,
            $message,
            $data,
            $url
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
            $actionId,
            $actionType,
            true,
            $message,
            $data,
            true
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
            $actionId,
            $actionType,
            true,
            $message,
            $data,
            true
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
            $actionId,
            $actionType,
            true,
            $message,
            $data,
            $nextActionId
        );
    }

    /**
     * Add redirect URL
     */
    public function withRedirect(string $url): self
    {
        return new self(
            $this->actionId,
            $this->actionType,
            $this->success,
            $this->message,
            $this->data,
            $this->errors,
            $url,
            $this->closeModal,
            $this->refreshPage,
            $this->nextAction,
            $this->metadata
        );
    }

    /**
     * Set to close modal
     */
    public function withCloseModal(): self
    {
        return new self(
            $this->actionId,
            $this->actionType,
            $this->success,
            $this->message,
            $this->data,
            $this->errors,
            $this->redirectUrl,
            true,
            $this->refreshPage,
            $this->nextAction,
            $this->metadata
        );
    }

    /**
     * Set to refresh page
     */
    public function withRefresh(): self
    {
        return new self(
            $this->actionId,
            $this->actionType,
            $this->success,
            $this->message,
            $this->data,
            $this->errors,
            $this->redirectUrl,
            $this->closeModal,
            true,
            $this->nextAction,
            $this->metadata
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
            $data['action_id'],
            ActionTypeEnum::from($data['action_type']),
            $data['success'],
            $data['message'] ?? null,
            $data['data'] ?? [],
            $data['errors'] ?? [],
            $data['redirect_url'] ?? null,
            $data['close_modal'] ?? false,
            $data['refresh_page'] ?? false,
            $data['next_action'] ?? null,
            $data['metadata'] ?? []
        );
    }

    public function getActionId(): string
    {
        return $this->actionId;
    }

    public function getActionType(): ActionTypeEnum
    {
        return $this->actionType;
    }

    public function getSuccess(): bool
    {
        return $this->success;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    public function getCloseModal(): bool
    {
        return $this->closeModal;
    }

    public function getRefreshPage(): bool
    {
        return $this->refreshPage;
    }

    public function getNextAction(): ?string
    {
        return $this->nextAction;
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
