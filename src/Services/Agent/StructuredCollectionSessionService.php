<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\StructuredCollectionDefinition;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Localization\LocaleResourceService;

class StructuredCollectionSessionService
{
    protected const TURN_FUNCTION = 'structured_collection_turn';

    public function __construct(
        protected AIEngineService $ai,
        protected StructuredCollectionCallbackService $callbacks,
        protected StructuredCollectionFieldPresenter $fields,
        protected ?StructuredCollectionPreviewRenderer $previews = null,
        protected ?LocaleResourceService $locales = null
    ) {
    }

    public function handle(string $message, string $sessionId, mixed $userId, array $options): ?AIResponse
    {
        $state = $this->state($sessionId, $userId);
        $definition = null;

        if ($state === null) {
            $collection = $options['collection'] ?? null;
            if (!is_array($collection) || ($collection['enabled'] ?? true) === false) {
                return null;
            }

            $definition = StructuredCollectionDefinition::fromArray($collection);
            $state = [
                'status' => 'collecting',
                'data' => [],
                'definition' => $definition->toArray(),
                'language' => null,
                'started_at' => now()->toIso8601String(),
            ];
        } else {
            $definition = StructuredCollectionDefinition::fromArray((array) ($state['definition'] ?? []));
        }

        // UI controls can submit canonical schema values as `field: value`.
        // These values need no probabilistic extraction: accepting them
        // locally avoids a model call and prevents smaller models from
        // returning a scalar for multiselect fields.
        $turn = $this->deterministicEnumTurn($message, $definition)
            ?? $this->askAI($message, $state, $definition, $options);
        $state = $this->applyTurn($state, $turn);

        if (!empty($turn['user_cancelled'])) {
            $this->forget($sessionId, $userId);

            return $this->response(
                (string) ($turn['assistant_message'] ?? $this->fallbackMessage('cancelled', $definition, $state)),
                $options,
                $definition,
                $state,
                'cancelled'
            );
        }

        $missing = $this->missingRequired($definition, (array) ($state['data'] ?? []));
        if ($missing !== []) {
            $state['status'] = 'collecting';
            $state['missing_fields'] = $missing;
            $this->put($sessionId, $userId, $state);

            return $this->response(
                ($turn['_transport'] ?? null) === 'native_tools'
                    ? $this->fallbackQuestion($missing[0], $definition, $state['language'] ?? null)
                    : (string) ($turn['assistant_message'] ?? $this->fallbackQuestion($missing[0], $definition)),
                $options,
                $definition,
                $state,
                'collecting'
            );
        }

        $confirmed = (bool) ($turn['user_confirmed'] ?? false);
        if ($definition->confirmBeforeComplete && !$confirmed) {
            $state['status'] = 'awaiting_confirmation';
            $state['missing_fields'] = [];
            $this->put($sessionId, $userId, $state);

            return $this->response(
                $this->confirmationMessage($definition, $state),
                $options,
                $definition,
                $state,
                'awaiting_confirmation'
            );
        }

        $state['status'] = 'completed';
        $state['missing_fields'] = [];
        $state['completed_at'] = now()->toIso8601String();
        $payload = $this->completionPayload($sessionId, $userId, $definition, $state);
        $this->callbacks->dispatch((array) ($definition->callback ?? ['type' => 'event']), $payload);

        if ($definition->closeOnComplete) {
            $this->forget($sessionId, $userId);
        } else {
            $this->put($sessionId, $userId, $state);
        }

        return $this->response(
            (string) ($turn['assistant_message'] ?? $this->fallbackMessage('completed', $definition, $state)),
            $options,
            $definition,
            $state,
            'completed',
            ['completed' => true, 'callback_dispatched' => true]
        );
    }

    public function isActive(string $sessionId, mixed $userId = null): bool
    {
        return $this->state($sessionId, $userId) !== null;
    }

    protected function askAI(string $message, array $state, StructuredCollectionDefinition $definition, array $options): array
    {
        $transport = (string) ($options['structured_collection_transport']
            ?? config('ai-agent.structured_collection.transport', 'prompt_json'));
        $nativeFields = $transport === 'native_tools'
            ? $this->nativeFieldNames($definition, $state, $options)
            : null;
        $request = new AIRequest(
            prompt: $transport === 'native_tools'
                ? $this->nativePrompt($message, $state, $definition)
                : $this->prompt($message, $state, $definition),
            engine: EngineEnum::from((string) ($options['engine'] ?? config('ai-engine.default', 'openai'))),
            model: EntityEnum::from((string) ($options['model'] ?? config('ai-engine.default_model', 'gpt-4o-mini'))),
            maxTokens: (int) config('ai-agent.structured_collection.max_tokens', 900),
            temperature: (float) config('ai-agent.structured_collection.temperature', 0.1),
            metadata: [
                'structured_collection' => true,
                'structured_collection_transport' => $transport,
            ]
        );

        if ($transport === 'native_tools') {
            $request = $request->withFunctions(
                [$this->nativeTurnFunction($definition, $nativeFields)],
                'required'
            );
        }

        $response = $this->ai->generate($request);

        $decoded = $transport === 'native_tools' ? $this->decodeNativeTurn($response, $definition) : [];
        if ($decoded === []) {
            // Some compatible gateways return a text JSON body even when
            // native tools were requested. Keep that response usable without
            // issuing a second, billable request.
            $decoded = $this->decodeJson($response->getContent());
        }
        if ($decoded === []) {
            Log::channel('ai-engine')->warning('Structured collection AI turn returned invalid JSON.', [
                'session_status' => $state['status'] ?? null,
                'collection' => $definition->name,
                'transport' => $transport,
            ]);
        }
        $decoded['_transport'] = $transport;

        return $decoded;
    }

    /**
     * @param list<string>|null $fieldNames
     */
    protected function nativeTurnFunction(
        StructuredCollectionDefinition $definition,
        ?array $fieldNames = null
    ): array
    {
        $allProperties = $definition->properties();
        $fieldNames ??= array_values(array_keys($allProperties));
        $fieldNames = array_values(array_filter(
            array_unique(array_map('strval', $fieldNames)),
            static fn (string $field): bool => array_key_exists($field, $allProperties)
        ));
        $properties = array_map(
            fn (mixed $property): array => $this->nativePropertySchema((array) $property),
            array_intersect_key($allProperties, array_flip($fieldNames))
        );
        $properties = array_merge($properties, [
            '__remove_fields' => [
                'type' => 'array',
                'description' => 'Previously collected fields the user explicitly removed in this turn.',
                'items' => array_filter([
                    'type' => 'string',
                    'enum' => $fieldNames !== [] ? $fieldNames : null,
                ], static fn (mixed $value): bool => $value !== null),
            ],
            '__user_confirmed' => ['type' => 'boolean', 'description' => 'True only when the user explicitly confirms while awaiting confirmation.'],
            '__user_cancelled' => ['type' => 'boolean', 'description' => 'True only when the user explicitly cancels the collection.'],
            '__ready_for_confirmation' => ['type' => 'boolean', 'description' => 'True when all required fields are now available.'],
            '__assistant_message' => ['type' => 'string', 'description' => 'Same-language reply asking only for the first still-missing required field, or asking for confirmation.'],
            '__language' => ['type' => 'string', 'description' => 'Language code detected from the latest user message.'],
        ]);

        return [
            'name' => self::TURN_FUNCTION,
            'description' => 'Extract every schema-supported value supplied or corrected in the latest user message and return the next structured-collection turn.',
            'parameters' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => [
                    '__remove_fields',
                    '__user_confirmed',
                    '__user_cancelled',
                    '__ready_for_confirmation',
                    '__assistant_message',
                    '__language',
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    protected function nativeFieldNames(
        StructuredCollectionDefinition $definition,
        array $state,
        array $options
    ): array {
        $scope = (string) ($options['structured_collection_native_field_scope']
            ?? config('ai-agent.structured_collection.native_field_scope', 'all'));
        if ($scope !== 'required') {
            return array_values(array_keys($definition->properties()));
        }

        return array_values(array_unique(array_merge(
            $definition->requiredFields(),
            array_keys((array) ($state['data'] ?? []))
        )));
    }

    protected function nativePrompt(
        string $message,
        array $state,
        StructuredCollectionDefinition $definition
    ): string {
        $data = json_encode($state['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $missing = json_encode(
            $this->missingRequired($definition, (array) ($state['data'] ?? [])),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $description = trim((string) $definition->description);
        $status = (string) ($state['status'] ?? 'collecting');

        return <<<PROMPT
Manage the structured collection "{$definition->name}" by calling structured_collection_turn exactly once.
Extract EVERY supported value explicitly supplied or corrected in the latest message; do not stop after one field, do not invent values, and let the latest correction win.
The first-missing rule controls only the assistant reply: ask naturally for one missing required field, in the user's language. If none are missing, summarize and ask for confirmation. Do not ask for optional fields.
Collection guidance: {$description}
Current status: {$status}
Missing required fields: {$missing}
Current data: {$data}
Latest user message: {$message}
PROMPT;
    }

    /**
     * Remove package presentation metadata before sending a field through a
     * provider's JSON Schema tool contract.
     */
    protected function nativePropertySchema(array $schema): array
    {
        $format = isset($schema['format']) && is_string($schema['format'])
            ? trim($schema['format'])
            : '';
        $schema = array_intersect_key($schema, array_flip([
            'type',
            'description',
            'enum',
            'properties',
            'required',
            'items',
            'additionalProperties',
            'oneOf',
            'anyOf',
            'allOf',
            'minimum',
            'maximum',
            'minLength',
            'maxLength',
            'pattern',
            'minItems',
            'maxItems',
        ]));
        if ($format !== '') {
            $description = trim((string) ($schema['description'] ?? ''));
            $schema['description'] = trim($description . ' Expected format: ' . $format . '.');
        }

        if (is_array($schema['properties'] ?? null)) {
            $schema['properties'] = array_map(
                fn (mixed $property): array => $this->nativePropertySchema((array) $property),
                $schema['properties']
            );
        }
        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = $this->nativePropertySchema($schema['items']);
        }

        return $schema;
    }

    protected function decodeNativeTurn(
        AIResponse $response,
        StructuredCollectionDefinition $definition
    ): array
    {
        $call = $response->getFunctionCall();
        if (! is_array($call) || ($call['name'] ?? null) !== self::TURN_FUNCTION) {
            return [];
        }

        $arguments = $call['arguments'] ?? [];
        if (is_string($arguments) && trim($arguments) !== '') {
            $arguments = json_decode($arguments, true);
        }
        if (! is_array($arguments)) {
            return [];
        }

        // Accept the nested shape as a compatibility fallback for gateways
        // that synthesize the documented response object themselves.
        if (is_array($arguments['data_patch'] ?? null)) {
            return $arguments;
        }

        return [
            'data_patch' => array_intersect_key($arguments, $definition->properties()),
            'remove_fields' => (array) ($arguments['__remove_fields'] ?? []),
            'user_confirmed' => (bool) ($arguments['__user_confirmed'] ?? false),
            'user_cancelled' => (bool) ($arguments['__user_cancelled'] ?? false),
            'ready_for_confirmation' => (bool) ($arguments['__ready_for_confirmation'] ?? false),
            'assistant_message' => (string) ($arguments['__assistant_message'] ?? ''),
            'language' => (string) ($arguments['__language'] ?? ''),
        ];
    }

    protected function prompt(
        string $message,
        array $state,
        StructuredCollectionDefinition $definition
    ): string
    {
        $schema = json_encode($definition->schema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $data = json_encode($state['data'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $missingRequired = json_encode($this->missingRequired($definition, (array) ($state['data'] ?? [])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $status = (string) ($state['status'] ?? 'collecting');

        return <<<PROMPT
You are managing a structured data collection chat.
Extract every schema-supported value the user provided or corrected in this latest message into data_patch.
Extraction is exhaustive for the latest message: include all supplied fields in one turn, even fields later than the first missing field. Never stop after extracting only one field.
Detect the user's language and write assistant_message in that language; reply in the same language as the user unless the user explicitly asks otherwise.
Do not translate JSON field keys. Field keys must stay exactly as defined by the schema.
Never invent missing values. Ask naturally for the next missing required value.
For enum fields and option fields, store only canonical values from enum/options. Never store translated labels for those fields.
Do not ask for optional fields unless the user already mentioned them or a field explicitly has metadata.ask_optional=true.
Optional fields are out of scope by default.
If all required fields are present and confirmation is required, summarize the collected data and ask for confirmation.
When asking for confirmation, explicitly ask whether the user confirms the collected data.
If the user confirms while the current status is awaiting_confirmation, set user_confirmed to true.
If the user cancels, set user_cancelled to true.

Critical response rule:
- The first-missing-field rule controls only assistant_message; it must never limit data_patch extraction.
- If missing_required_fields is not empty, assistant_message must ask for only the first missing required field.
- If missing_required_fields is empty and current status is not awaiting_confirmation, assistant_message must summarize collected data and explicitly ask for confirmation.
- If missing_required_fields is empty, assistant_message must not ask for notes, comments, optional fields, or extra data.

Return only valid JSON with these keys:
{
  "data_patch": {},
  "remove_fields": [],
  "user_confirmed": false,
  "user_cancelled": false,
  "ready_for_confirmation": false,
  "assistant_message": "",
  "language": ""
}

Collection name: {$definition->name}
Collection description: {$definition->description}
Current status: {$status}
missing_required_fields: {$missingRequired}
JSON schema:
{$schema}

Current collected data:
{$data}

Latest user message:
{$message}
PROMPT;
    }

    protected function applyTurn(array $state, array $turn): array
    {
        $data = is_array($state['data'] ?? null) ? $state['data'] : [];
        $patch = is_array($turn['data_patch'] ?? null) ? $turn['data_patch'] : [];

        foreach ($patch as $key => $value) {
            if (is_string($key) && $value !== null && $value !== '') {
                $data[$key] = $value;
            }
        }

        foreach ((array) ($turn['remove_fields'] ?? []) as $field) {
            if (is_string($field)) {
                unset($data[$field]);
            }
        }

        $state['data'] = $data;
        if (isset($turn['language']) && is_string($turn['language']) && trim($turn['language']) !== '') {
            $state['language'] = trim($turn['language']);
        }
        $state['updated_at'] = now()->toIso8601String();

        return $state;
    }

    /**
     * Resolve an exact schema-qualified enum response without invoking AI.
     *
     * Supported wire forms:
     * - `field: canonical_value`
     * - `field: value_one,value_two` for enum arrays
     * - `field: ["value_one","value_two"]` for enum arrays
     *
     * Free text, labels, unknown fields, and invalid values deliberately fall
     * through to the configured model, preserving existing behavior.
     */
    protected function deterministicEnumTurn(
        string $message,
        StructuredCollectionDefinition $definition
    ): ?array {
        if (!preg_match('/\A([A-Za-z][A-Za-z0-9_-]{0,127})\s*:\s*(.+?)\s*\z/us', trim($message), $matches)) {
            return null;
        }

        $field = $matches[1];
        $rawValue = trim($matches[2]);
        $property = $definition->properties()[$field] ?? null;
        if (!is_array($property) || $rawValue === '') {
            return null;
        }

        $type = $property['type'] ?? null;
        if ($type === 'array') {
            $allowed = array_values(array_map('strval', (array) ($property['items']['enum'] ?? [])));
            $values = $this->deterministicEnumArrayValues($rawValue, $allowed);

            return $values === null ? null : ['data_patch' => [$field => $values]];
        }

        $allowed = array_values(array_map('strval', (array) ($property['enum'] ?? [])));
        if ($allowed === [] || !in_array($rawValue, $allowed, true)) {
            return null;
        }

        return ['data_patch' => [$field => $rawValue]];
    }

    /**
     * @param list<string> $allowed
     * @return list<string>|null
     */
    protected function deterministicEnumArrayValues(string $rawValue, array $allowed): ?array
    {
        if ($allowed === []) {
            return null;
        }
        if (in_array($rawValue, $allowed, true)) {
            return [$rawValue];
        }

        $decoded = json_decode($rawValue, true);
        $values = is_array($decoded)
            ? $decoded
            : preg_split('/\s*,\s*/u', $rawValue, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($values) || $values === []) {
            return null;
        }

        $normalized = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                return null;
            }
            $value = trim((string) $value);
            if ($value === '') {
                return null;
            }
            $normalized[] = $value;
        }
        $values = array_values(array_unique($normalized));

        return array_diff($values, $allowed) === [] ? $values : null;
    }

    protected function response(
        string $message,
        array $options,
        StructuredCollectionDefinition $definition,
        array $state,
        string $status,
        array $extra = []
    ): AIResponse {
        $fields = $this->fields->present($definition, isset($state['language']) ? (string) $state['language'] : null);
        $collection = array_merge([
            'name' => $definition->name,
            'status' => $status,
            'data' => $state['data'] ?? [],
            'missing_fields' => $state['missing_fields'] ?? $this->missingRequired($definition, (array) ($state['data'] ?? [])),
            'language' => $state['language'] ?? null,
            'schema' => $definition->schema(),
            'fields' => $fields,
        ], $extra);

        $preview = $this->previews()->render($definition, $collection, $fields, $status);
        if ($preview !== null) {
            $collection['preview'] = $preview;
        }

        return AIResponse::success(
            content: $message,
            engine: (string) ($options['engine'] ?? 'openai'),
            model: (string) ($options['model'] ?? 'gpt-4o-mini'),
            metadata: [
                'collection' => $collection,
            ]
        );
    }

    protected function previews(): StructuredCollectionPreviewRenderer
    {
        return $this->previews ??= new StructuredCollectionPreviewRenderer();
    }

    protected function completionPayload(string $sessionId, mixed $userId, StructuredCollectionDefinition $definition, array $state): array
    {
        return [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'status' => 'completed',
            'collection' => $definition->name,
            'data' => $state['data'] ?? [],
            'metadata' => [
                'language' => $state['language'] ?? null,
                'confirmed' => true,
                'completed_at' => $state['completed_at'] ?? now()->toIso8601String(),
                'definition' => $definition->toArray(),
            ],
        ];
    }

    protected function missingRequired(StructuredCollectionDefinition $definition, array $data): array
    {
        return array_values(array_filter($definition->requiredFields(), static function (string $field) use ($data): bool {
            return !array_key_exists($field, $data)
                || $data[$field] === null
                || $data[$field] === ''
                || $data[$field] === [];
        }));
    }

    protected function decodeJson(string $content): array
    {
        $content = trim($content);
        $content = (string) preg_replace('/^```(?:json)?|```$/m', '', $content);
        $decoded = json_decode(trim($content), true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function fallbackQuestion(
        string $field,
        StructuredCollectionDefinition $definition,
        ?string $language = null
    ): string
    {
        $description = $definition->properties()[$field]['description'] ?? null;
        $subject = is_string($description) && $description !== '' ? $description : $field;

        if (str_starts_with(strtolower((string) $language), 'ar')) {
            return "يرجى تقديم {$subject}.";
        }

        return "Please provide {$subject}.";
    }

    protected function fallbackMessage(string $status, StructuredCollectionDefinition $definition, array $state): string
    {
        return match ($status) {
            'awaiting_confirmation' => $this->confirmationMessage($definition, $state),
            'completed' => 'The collection is complete.',
            'cancelled' => 'The collection was cancelled.',
            default => "Collection {$definition->name} is in progress.",
        };
    }

    protected function confirmationMessage(StructuredCollectionDefinition $definition, array $state): string
    {
        $locale = $this->language($state);
        $summary = $this->summaryLines((array) ($state['data'] ?? []), $locale);
        $translated = $this->translate('structured_collection.awaiting_confirmation', [
            'summary' => $summary,
        ], $locale);

        if ($translated !== '') {
            return $translated;
        }

        return str_starts_with($locale, 'ar')
            ? "يرجى تأكيد البيانات التالية:\n{$summary}"
            : "Please confirm the collected data:\n{$summary}";
    }

    protected function summaryLines(array $data, string $locale = 'en'): string
    {
        $lines = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || $value === null || $value === '' || $value === []) {
                continue;
            }

            $lines[] = '- ' . $this->summaryFieldLabel($key, $locale) . ': ' . $this->stringifyValue($value);
        }

        return implode("\n", $lines);
    }

    /**
     * Localized label for a collected field in the confirmation summary, so the
     * summary reads in the conversation language instead of by raw key. Falls
     * back to a humanized version of the key when no translation exists.
     */
    protected function summaryFieldLabel(string $key, string $locale): string
    {
        $translated = $this->translate('structured_collection.fields.' . $key, [], $locale);
        if ($translated !== '') {
            return $translated;
        }

        $label = preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', trim($key))) ?: $key;

        return mb_convert_case($label, MB_CASE_TITLE, 'UTF-8');
    }

    protected function stringifyValue(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return (string) $value;
    }

    protected function translate(string $key, array $replace, string $locale): string
    {
        if ($this->locales instanceof LocaleResourceService) {
            return $this->locales->translation("ai-engine::messages.{$key}", $replace, $locale);
        }

        return '';
    }

    protected function language(array $state): string
    {
        $language = isset($state['language']) ? strtolower(str_replace('_', '-', trim((string) $state['language']))) : '';

        return $language !== '' ? $language : 'en';
    }

    protected function state(string $sessionId, mixed $userId): ?array
    {
        $state = Cache::get($this->cacheKey($sessionId, $userId));

        return is_array($state) ? $state : null;
    }

    protected function put(string $sessionId, mixed $userId, array $state): void
    {
        Cache::put($this->cacheKey($sessionId, $userId), $state, now()->addSeconds((int) config('ai-agent.structured_collection.ttl_seconds', 3600)));
    }

    protected function forget(string $sessionId, mixed $userId): void
    {
        Cache::forget($this->cacheKey($sessionId, $userId));
    }

    protected function cacheKey(string $sessionId, mixed $userId): string
    {
        $scope = $userId === null || $userId === '' ? 'guest' : (string) $userId;

        return "agent_structured_collection:{$sessionId}:{$scope}";
    }
}
