<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class StructuredCollectionDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null,
        protected array $schema = ['type' => 'object', 'properties' => [], 'required' => []],
        public readonly bool $confirmBeforeComplete = true,
        public readonly bool $closeOnComplete = true,
        public readonly ?array $callback = null,
        public readonly array $metadata = []
    ) {
    }

    public static function make(string $name): self
    {
        return new self(name: $name);
    }

    public static function fromArray(array $data): self
    {
        $schema = is_array($data['schema'] ?? null) ? $data['schema'] : [
            'type' => 'object',
            'properties' => is_array($data['fields'] ?? null) ? $data['fields'] : [],
            'required' => [],
        ];

        return new self(
            name: (string) ($data['name'] ?? $data['id'] ?? 'structured_collection'),
            description: isset($data['description']) ? (string) $data['description'] : null,
            schema: self::normalizeSchema($schema),
            confirmBeforeComplete: (bool) ($data['confirm_before_complete'] ?? true),
            closeOnComplete: (bool) ($data['close_on_complete'] ?? true),
            callback: is_array($data['callback'] ?? null) ? $data['callback'] : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : []
        );
    }

    public function description(string $description): self
    {
        return new self(
            name: $this->name,
            description: $description,
            schema: $this->schema,
            confirmBeforeComplete: $this->confirmBeforeComplete,
            closeOnComplete: $this->closeOnComplete,
            callback: $this->callback,
            metadata: $this->metadata
        );
    }

    /**
     * @param array<int, string>|null $enum
     */
    public function addField(
        string $name,
        string $type = 'string',
        bool $required = false,
        ?string $description = null,
        ?string $format = null,
        ?array $enum = null,
        array $metadata = []
    ): self {
        $schema = self::normalizeSchema($this->schema);
        $schema['properties'][$name] = array_filter([
            'type' => $type,
            'description' => $description,
            'format' => $format,
            'enum' => $enum,
            'metadata' => $metadata !== [] ? $metadata : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        if ($required) {
            $schema['required'] = array_values(array_unique(array_merge($schema['required'] ?? [], [$name])));
        }

        return new self(
            name: $this->name,
            description: $this->description,
            schema: $schema,
            confirmBeforeComplete: $this->confirmBeforeComplete,
            closeOnComplete: $this->closeOnComplete,
            callback: $this->callback,
            metadata: $this->metadata
        );
    }

    public function addText(string $name, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        return $this->addField($name, 'string', $required, $description, metadata: array_merge($metadata, ['ui' => 'text']));
    }

    public function addTextarea(string $name, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        return $this->addField($name, 'string', $required, $description, metadata: array_merge($metadata, ['ui' => 'textarea']));
    }

    public function addEmail(string $name, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        return $this->addField($name, 'string', $required, $description, 'email', metadata: array_merge($metadata, ['ui' => 'email']));
    }

    public function addUrl(string $name, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        return $this->addField($name, 'string', $required, $description, 'uri', metadata: array_merge($metadata, ['ui' => 'url']));
    }

    public function addPhone(string $name, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        return $this->addField($name, 'string', $required, $description, 'phone', metadata: array_merge($metadata, ['ui' => 'phone']));
    }

    public function addNumber(string $name, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        return $this->addField($name, 'number', $required, $description, metadata: array_merge($metadata, ['ui' => 'number']));
    }

    public function addInteger(string $name, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        return $this->addField($name, 'integer', $required, $description, metadata: array_merge($metadata, ['ui' => 'integer']));
    }

    public function addBoolean(string $name, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        return $this->addField($name, 'boolean', $required, $description, metadata: array_merge($metadata, ['ui' => 'boolean']));
    }

    public function addDate(string $name, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        return $this->addField($name, 'string', $required, $description, 'date', metadata: array_merge($metadata, ['ui' => 'date']));
    }

    public function addDateTime(string $name, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        return $this->addField($name, 'string', $required, $description, 'date-time', metadata: array_merge($metadata, ['ui' => 'datetime']));
    }

    public function addTime(string $name, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        return $this->addField($name, 'string', $required, $description, 'time', metadata: array_merge($metadata, ['ui' => 'time']));
    }

    public function addSelect(string $name, array $options, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        [$values, $normalizedOptions] = self::normalizeOptions($options);

        return $this->addField($name, 'string', $required, $description, enum: $values, metadata: array_merge($metadata, [
            'ui' => 'select',
            'options' => $normalizedOptions,
        ]));
    }

    public function addMultiSelect(string $name, array $options, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        [$values, $normalizedOptions] = self::normalizeOptions($options);
        $schema = self::normalizeSchema($this->schema);
        $schema['properties'][$name] = array_filter([
            'type' => 'array',
            'description' => $description,
            'items' => ['type' => 'string', 'enum' => $values],
            'metadata' => array_merge($metadata, [
                'ui' => 'multiselect',
                'options' => $normalizedOptions,
            ]),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        if ($required) {
            $schema['required'] = array_values(array_unique(array_merge($schema['required'] ?? [], [$name])));
        }

        return new self($this->name, $this->description, $schema, $this->confirmBeforeComplete, $this->closeOnComplete, $this->callback, $this->metadata);
    }

    public function addRadio(string $name, array $options, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        [$values, $normalizedOptions] = self::normalizeOptions($options);

        return $this->addField($name, 'string', $required, $description, enum: $values, metadata: array_merge($metadata, [
            'ui' => 'radio',
            'options' => $normalizedOptions,
        ]));
    }

    public function addCheckboxes(string $name, array $options, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        [$values, $normalizedOptions] = self::normalizeOptions($options);
        $schema = self::normalizeSchema($this->schema);
        $schema['properties'][$name] = array_filter([
            'type' => 'array',
            'description' => $description,
            'items' => ['type' => 'string', 'enum' => $values],
            'metadata' => array_merge($metadata, [
                'ui' => 'checkboxes',
                'options' => $normalizedOptions,
            ]),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        if ($required) {
            $schema['required'] = array_values(array_unique(array_merge($schema['required'] ?? [], [$name])));
        }

        return new self($this->name, $this->description, $schema, $this->confirmBeforeComplete, $this->closeOnComplete, $this->callback, $this->metadata);
    }

    public function addFile(string $name, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        return $this->addField($name, 'string', $required, $description, 'uri', metadata: array_merge($metadata, ['ui' => 'file']));
    }

    public function addHidden(string $name, string $type = 'string', bool $required = false, ?string $description = null, array $metadata = []): self
    {
        return $this->addField($name, $type, $required, $description, metadata: array_merge($metadata, ['ui' => 'hidden']));
    }

    public function addJson(string $name, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        return $this->addField($name, 'object', $required, $description, metadata: array_merge($metadata, ['ui' => 'json']));
    }

    public function addArray(string $name, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        return $this->addField($name, 'array', $required, $description, metadata: array_merge($metadata, ['ui' => 'array']));
    }

    public function addObject(string $name, bool $required = false, ?string $description = null, array $metadata = []): self
    {
        return $this->addField($name, 'object', $required, $description, metadata: array_merge($metadata, ['ui' => 'object']));
    }

    public function confirmBeforeComplete(bool $confirm = true): self
    {
        return new self($this->name, $this->description, $this->schema, $confirm, $this->closeOnComplete, $this->callback, $this->metadata);
    }

    public function closeOnComplete(bool $close = true): self
    {
        return new self($this->name, $this->description, $this->schema, $this->confirmBeforeComplete, $close, $this->callback, $this->metadata);
    }

    public function callbackUrl(string $url, string $method = 'POST', array $headers = []): self
    {
        return new self(
            $this->name,
            $this->description,
            $this->schema,
            $this->confirmBeforeComplete,
            $this->closeOnComplete,
            ['type' => 'url', 'url' => $url, 'method' => strtoupper($method), 'headers' => $headers],
            $this->metadata
        );
    }

    public function callbackEvent(): self
    {
        return new self(
            $this->name,
            $this->description,
            $this->schema,
            $this->confirmBeforeComplete,
            $this->closeOnComplete,
            ['type' => 'event'],
            $this->metadata
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'description' => $this->description,
            'schema' => self::normalizeSchema($this->schema),
            'confirm_before_complete' => $this->confirmBeforeComplete,
            'close_on_complete' => $this->closeOnComplete,
            'callback' => $this->callback,
            'metadata' => $this->metadata,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function schema(): array
    {
        return self::normalizeSchema($this->schema);
    }

    public function properties(): array
    {
        return (array) ($this->schema()['properties'] ?? []);
    }

    public function requiredFields(): array
    {
        return array_values(array_filter(
            (array) ($this->schema()['required'] ?? []),
            static fn (mixed $field): bool => is_string($field) && $field !== ''
        ));
    }

    protected static function normalizeSchema(array $schema): array
    {
        $schema['type'] = $schema['type'] ?? 'object';
        $schema['properties'] = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $schema['required'] = array_values(array_filter(
            (array) ($schema['required'] ?? []),
            static fn (mixed $field): bool => is_string($field) && trim($field) !== ''
        ));

        return $schema;
    }

    protected static function normalizeOptions(array $options): array
    {
        $values = [];
        $normalized = [];

        foreach ($options as $key => $option) {
            if (is_array($option)) {
                $value = (string) ($option['value'] ?? $key);
                $entry = ['value' => $value];

                if (isset($option['label']) && is_scalar($option['label'])) {
                    $entry['label'] = (string) $option['label'];
                }

                if (isset($option['labels']) && is_array($option['labels'])) {
                    $entry['labels'] = $option['labels'];
                }
            } else {
                $value = is_string($key) ? $key : (string) $option;
                $entry = ['value' => $value];

                if (is_string($key) && is_scalar($option)) {
                    $entry['label'] = (string) $option;
                }
            }

            if ($value === '') {
                continue;
            }

            $values[] = $value;
            $normalized[] = $entry;
        }

        return [array_values(array_unique($values)), $normalized];
    }
}
