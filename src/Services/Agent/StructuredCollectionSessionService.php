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

        $turn = $this->askAI($message, $state, $definition, $options);
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
                (string) ($turn['assistant_message'] ?? $this->fallbackQuestion($missing[0], $definition)),
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
        $response = $this->ai->generate(new AIRequest(
            prompt: $this->prompt($message, $state, $definition),
            engine: EngineEnum::from((string) ($options['engine'] ?? config('ai-engine.default', 'openai'))),
            model: EntityEnum::from((string) ($options['model'] ?? config('ai-engine.default_model', 'gpt-4o-mini'))),
            maxTokens: (int) config('ai-agent.structured_collection.max_tokens', 900),
            temperature: (float) config('ai-agent.structured_collection.temperature', 0.1),
            metadata: ['structured_collection' => true]
        ));

        $decoded = $this->decodeJson($response->getContent());
        if ($decoded === []) {
            Log::channel('ai-engine')->warning('Structured collection AI turn returned invalid JSON.', [
                'session_status' => $state['status'] ?? null,
                'collection' => $definition->name,
            ]);
        }

        return $decoded;
    }

    protected function prompt(string $message, array $state, StructuredCollectionDefinition $definition): string
    {
        $schema = json_encode($definition->schema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $data = json_encode($state['data'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $missingRequired = json_encode($this->missingRequired($definition, (array) ($state['data'] ?? [])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $status = (string) ($state['status'] ?? 'collecting');

        return <<<PROMPT
You are managing a structured data collection chat.
Extract only data the user provided or corrected in this latest message.
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

    protected function fallbackQuestion(string $field, StructuredCollectionDefinition $definition): string
    {
        $description = $definition->properties()[$field]['description'] ?? null;

        return is_string($description) && $description !== ''
            ? "Please provide {$description}."
            : "Please provide {$field}.";
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
