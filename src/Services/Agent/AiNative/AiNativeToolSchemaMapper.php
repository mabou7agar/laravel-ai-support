<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

class AiNativeToolSchemaMapper
{
    public const FINAL_TOOL = 'agent_runtime_final';
    public const ASK_USER_TOOL = 'agent_runtime_ask_user';

    /**
     * @param array<int, array<string, mixed>> $toolDocuments
     * @return array<int, array<string, mixed>>
     */
    public function map(array $toolDocuments): array
    {
        $definitions = [];

        foreach ($toolDocuments as $document) {
            $name = trim((string) ($document['name'] ?? ''));
            if ($name === '' || in_array($name, [self::FINAL_TOOL, self::ASK_USER_TOOL], true)) {
                continue;
            }

            $definitions[] = [
                'name' => $name,
                'description' => trim((string) ($document['description'] ?? '')),
                'parameters' => $this->objectSchema((array) ($document['parameters'] ?? [])),
            ];
        }

        $definitions[] = $this->finalDefinition();
        $definitions[] = $this->askUserDefinition();

        return $definitions;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function objectSchema(array $parameters): array
    {
        if (($parameters['type'] ?? null) === 'object' && is_array($parameters['properties'] ?? null)) {
            return $this->normalizeSchema($parameters);
        }

        $properties = [];
        $required = [];

        foreach ($parameters as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            if (($definition['required'] ?? false) === true) {
                $required[] = (string) $name;
            }

            if (is_bool($definition['required'] ?? null)) {
                unset($definition['required']);
            }
            $properties[(string) $name] = $this->normalizeSchema($definition);
        }

        return array_filter([
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ], static fn (mixed $value): bool => $value !== []);
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function normalizeSchema(array $schema): array
    {
        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            $types = array_values(array_unique(array_filter(array_map(
                static fn (mixed $value): string => is_string($value)
                    ? strtolower(trim($value))
                    : '',
                $type,
            ))));

            if ($types === [] || in_array('mixed', $types, true) || in_array('any', $types, true)) {
                unset($schema['type']);
            } else {
                $schema['type'] = $types;
            }
        } elseif (is_string($type)) {
            $type = strtolower(trim($type));
            if ($type === '' || $type === 'mixed' || $type === 'any') {
                unset($schema['type']);
            } else {
                $schema['type'] = $type;
            }
        } elseif ($type !== null) {
            unset($schema['type']);
        }

        if (is_array($schema['properties'] ?? null)) {
            $required = is_array($schema['required'] ?? null)
                ? array_values($schema['required'])
                : [];
            $properties = [];

            foreach ($schema['properties'] as $name => $property) {
                $property = is_array($property) ? $property : [];
                if (($property['required'] ?? false) === true) {
                    $required[] = (string) $name;
                }
                if (is_bool($property['required'] ?? null)) {
                    unset($property['required']);
                }
                $properties[(string) $name] = $this->normalizeSchema($property);
            }

            $schema['properties'] = $properties;
            if ($required !== []) {
                $schema['required'] = array_values(array_unique($required));
            } else {
                unset($schema['required']);
            }
        }

        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = $this->normalizeSchema($schema['items']);
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function finalDefinition(): array
    {
        return [
            'name' => self::FINAL_TOOL,
            'description' => 'Return the final answer to the user when no more application tools or user input are needed.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'message' => [
                        'type' => 'string',
                        'description' => 'The complete user-facing answer.',
                    ],
                    'data' => [
                        'type' => 'object',
                        'description' => 'Optional structured result data.',
                        'additionalProperties' => true,
                    ],
                ],
                'required' => ['message'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function askUserDefinition(): array
    {
        return [
            'name' => self::ASK_USER_TOOL,
            'description' => 'Ask the user for information that cannot be obtained from an available non-confirming lookup tool.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'message' => [
                        'type' => 'string',
                        'description' => 'The focused question to show the user.',
                    ],
                    'required_inputs' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Stable names of the missing inputs.',
                    ],
                    'data' => [
                        'type' => 'object',
                        'description' => 'Optional current payload or structured context.',
                        'additionalProperties' => true,
                    ],
                ],
                'required' => ['message'],
                'additionalProperties' => false,
            ],
        ];
    }
}
