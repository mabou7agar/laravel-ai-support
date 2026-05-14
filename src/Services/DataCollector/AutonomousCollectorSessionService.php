<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\DataCollector;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\DTOs\AutonomousCollectorSessionState;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Agent\Collectors\AutonomousCollectorTurnProcessor;
use LaravelAIEngine\Services\Agent\Collectors\CollectorConfirmationService;
use LaravelAIEngine\Services\Agent\Collectors\CollectorInputSchemaBuilder;
use LaravelAIEngine\Services\Agent\Collectors\CollectorPromptBuilder;
use LaravelAIEngine\Services\Agent\Collectors\CollectorReroutePolicy;
use LaravelAIEngine\Services\Agent\Collectors\CollectorSummaryRenderer;
use LaravelAIEngine\Services\Agent\Collectors\CollectorToolCallParser;
use LaravelAIEngine\Services\Agent\Collectors\CollectorToolExecutionService;
use LaravelAIEngine\Services\Localization\LocaleResourceService;

class AutonomousCollectorSessionService
{
    protected string $cachePrefix = 'autonomous_collector_';
    protected int $cacheTtl = 3600;
    protected array $registeredConfigs = [];

    public function __construct(
        protected AIEngineService $ai,
        protected ?LocaleResourceService $localeResources = null,
        protected ?AutonomousCollectorTurnProcessor $turnProcessor = null,
    ) {
    }

    public function start(
        string $sessionId,
        AutonomousCollectorConfig $config,
        string $initialMessage = ''
    ): AutonomousCollectorResponse {
        $configName = $this->registerRuntimeConfig($sessionId, $config);

        $state = new AutonomousCollectorSessionState(
            configName: $configName,
            status: AutonomousCollectorSessionState::STATUS_COLLECTING,
        );

        $this->saveState($sessionId, $this->withStoredConfig($state->toArray(), $sessionId, $config));

        if ($initialMessage !== '') {
            return $this->process($sessionId, $initialMessage);
        }

        $greeting = $this->generateGreeting($config);
        $state->appendConversation('assistant', $greeting);
        $this->saveState($sessionId, $this->withStoredConfig($state->toArray(), $sessionId, $config));

        return new AutonomousCollectorResponse(
            success: true,
            message: $greeting,
            status: AutonomousCollectorSessionState::STATUS_COLLECTING,
            collectedData: [],
            isComplete: false,
        );
    }

    public function process(string $sessionId, string $message): AutonomousCollectorResponse
    {
        $storedState = $this->getState($sessionId);
        if ($storedState === null) {
            return new AutonomousCollectorResponse(
                success: false,
                message: $this->locale()->translation('ai-engine::runtime.data_collector.api.active_session_not_found')
                    ?: 'No active session found.',
                status: 'error',
            );
        }

        $config = $this->resolveConfigForSession($storedState);
        if (!$config instanceof AutonomousCollectorConfig) {
            return new AutonomousCollectorResponse(
                success: false,
                message: $this->locale()->translation('ai-engine::runtime.autonomous_collector.config_not_found')
                    ?: 'Collector configuration is unavailable for this session. Re-register the config and start a new session.',
                status: 'error',
                error: 'collector_config_unavailable',
            );
        }

        $state = AutonomousCollectorSessionState::fromArray($storedState);
        $context = new UnifiedActionContext($sessionId);
        $context->set('autonomous_collector', $state->toArray());

        $agentResponse = $this->turnProcessor()->process($sessionId, $message, $config, $state, $context);
        $updatedState = $context->get('autonomous_collector');

        if (is_array($updatedState)) {
            $this->saveState($sessionId, $this->withStoredConfig($updatedState, $sessionId, $config));
        } else {
            $updatedState = $this->terminalStateFromResponse($storedState, $agentResponse);
            $this->saveState($sessionId, $this->withStoredConfig($updatedState, $sessionId, $config));
        }

        return $this->toAutonomousResponse($agentResponse, $updatedState);
    }

    public function confirm(string $sessionId): AutonomousCollectorResponse
    {
        $state = $this->getState($sessionId);

        if (!$state || ($state['status'] ?? null) !== AutonomousCollectorSessionState::STATUS_CONFIRMING) {
            return new AutonomousCollectorResponse(
                success: false,
                message: $this->locale()->translation('ai-engine::runtime.autonomous_collector.no_active_session')
                    ?: 'No pending confirmation.',
                status: 'error',
            );
        }

        $yesToken = $this->locale()->lexicon('intent.confirm', default: ['yes'])[0] ?? 'yes';

        return $this->process($sessionId, $yesToken);
    }

    public function hasSession(string $sessionId): bool
    {
        return Cache::has($this->cachePrefix . $sessionId);
    }

    public function getStatus(string $sessionId): ?string
    {
        $state = $this->getState($sessionId);

        return $state['status'] ?? null;
    }

    public function getData(string $sessionId): array
    {
        $state = $this->getState($sessionId);

        return $state['collected_data'] ?? [];
    }

    public function deleteSession(string $sessionId): void
    {
        Cache::forget($this->cachePrefix . $sessionId);
    }

    public function registerConfig(AutonomousCollectorConfig $config): void
    {
        $name = trim((string) ($config->name ?? ''));
        if ($name !== '') {
            $this->registerConfigAs($name, $config);
        }
    }

    public function registerConfigAs(string $name, AutonomousCollectorConfig $config): void
    {
        $normalized = trim($name);
        if ($normalized === '') {
            return;
        }

        $this->registeredConfigs[$normalized] = $config;
    }

    public function getRegisteredConfig(string $name): ?AutonomousCollectorConfig
    {
        return $this->registeredConfigs[$name] ?? null;
    }

    protected function toAutonomousResponse(AgentResponse $response, array $state): AutonomousCollectorResponse
    {
        $status = (string) ($state['status'] ?? ($response->success ? 'collecting' : 'error'));
        if (!$response->success) {
            $status = 'error';
        }

        return new AutonomousCollectorResponse(
            success: $response->success,
            message: $response->message,
            status: $status,
            collectedData: (array) ($state['collected_data'] ?? $response->data['collected_data'] ?? []),
            isComplete: $status === AutonomousCollectorSessionState::STATUS_COMPLETED,
            isCancelled: $status === 'cancelled',
            requiresConfirmation: $status === AutonomousCollectorSessionState::STATUS_CONFIRMING,
            result: $response->data['result'] ?? $state['result'] ?? null,
            turnCount: (int) ($state['turn_count'] ?? 0),
            toolResults: (array) ($state['tool_results'] ?? []),
            error: $response->success ? null : ($response->data['error'] ?? $response->message),
        );
    }

    protected function terminalStateFromResponse(array $previousState, AgentResponse $response): array
    {
        if ($response->success && str_contains(strtolower($response->message), 'cancel')) {
            $previousState['status'] = 'cancelled';
            return $previousState;
        }

        if ($response->success && isset($response->data['result'])) {
            $previousState['status'] = AutonomousCollectorSessionState::STATUS_COMPLETED;
            $previousState['result'] = $response->data['result'];
            $previousState['collected_data'] = $response->data['collected_data'] ?? $previousState['collected_data'] ?? [];
            $previousState['completed_at'] = now()->toIso8601String();

            return $previousState;
        }

        if (!$response->success) {
            $previousState['status'] = 'error';
            $previousState['error'] = $response->message;
        }

        return $previousState;
    }

    protected function withStoredConfig(array $state, string $sessionId, AutonomousCollectorConfig $config): array
    {
        $state['session_id'] = $sessionId;
        $state['config'] = $this->serializeConfig($config);

        return $state;
    }

    protected function saveState(string $sessionId, array $state): void
    {
        Cache::put($this->cachePrefix . $sessionId, $state, $this->cacheTtl);
    }

    protected function getState(string $sessionId): ?array
    {
        $state = Cache::get($this->cachePrefix . $sessionId);

        return is_array($state) ? $state : null;
    }

    protected function generateGreeting(AutonomousCollectorConfig $config): string
    {
        $greeting = $this->locale()->translation(
            'ai-engine::runtime.autonomous_collector.greeting',
            ['goal' => $config->goal]
        );

        return $greeting !== '' ? $greeting : "Hello! I'll help you {$config->goal}.";
    }

    protected function serializeConfig(AutonomousCollectorConfig $config): array
    {
        return [
            'goal' => $config->goal,
            'description' => $config->description,
            'output_schema' => $config->outputSchema,
            'confirm_before_complete' => $config->confirmBeforeComplete,
            'system_prompt_addition' => $config->systemPromptAddition,
            'context' => $config->context,
            'entity_resolvers_available' => $config->entityResolvers !== [],
            'max_turns' => $config->maxTurns,
            'name' => $config->name,
            'has_tools' => $config->tools !== [],
            'tool_names' => array_values(array_keys($config->tools)),
            'tools_meta' => array_map(
                static fn (mixed $tool): array => is_array($tool)
                    ? [
                        'description' => $tool['description'] ?? '',
                        'parameters' => $tool['parameters'] ?? [],
                    ]
                    : [],
                $config->tools
            ),
        ];
    }

    protected function deserializeConfig(array $data): AutonomousCollectorConfig
    {
        return new AutonomousCollectorConfig(
            goal: (string) ($data['goal'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            tools: [],
            outputSchema: (array) ($data['output_schema'] ?? []),
            confirmBeforeComplete: (bool) ($data['confirm_before_complete'] ?? true),
            systemPromptAddition: $data['system_prompt_addition'] ?? null,
            context: (array) ($data['context'] ?? []),
            maxTurns: (int) ($data['max_turns'] ?? 20),
            name: $data['name'] ?? null,
        );
    }

    protected function resolveConfigForSession(array $state): ?AutonomousCollectorConfig
    {
        $serialized = $state['config'] ?? null;
        if (!is_array($serialized)) {
            return null;
        }

        $configName = $this->normalizeConfigName($state['config_name'] ?? $serialized['name'] ?? null);
        if ($configName !== null) {
            $registered = $this->getRegisteredConfig($configName);
            if ($registered instanceof AutonomousCollectorConfig) {
                return $registered;
            }

            $fromRegistry = AutonomousCollectorRegistry::getConfig($configName);
            if ($fromRegistry instanceof AutonomousCollectorConfig) {
                $this->registerConfigAs($configName, $fromRegistry);

                return $fromRegistry;
            }
        }

        $deserialized = $this->deserializeConfig($serialized);
        $expectsRuntimeCallbacks = (bool) ($serialized['has_tools'] ?? false)
            || (bool) ($serialized['entity_resolvers_available'] ?? false);
        if ($expectsRuntimeCallbacks) {
            Log::warning('Autonomous collector runtime callbacks unavailable after session restore', [
                'session_id' => $state['session_id'] ?? null,
                'config_name' => $configName,
                'tool_names' => $serialized['tool_names'] ?? [],
            ]);

            return null;
        }

        return $deserialized;
    }

    protected function registerRuntimeConfig(string $sessionId, AutonomousCollectorConfig $config): string
    {
        $configName = $this->normalizeConfigName($config->name)
            ?? 'collector_' . substr(sha1($sessionId . '|' . $config->goal), 0, 16);

        $this->registerConfigAs($configName, $config);

        if (!AutonomousCollectorRegistry::has($configName)) {
            AutonomousCollectorRegistry::register($configName, [
                'config' => $config,
                'goal' => $config->goal,
                'description' => $config->description,
            ]);
        }

        return $configName;
    }

    protected function normalizeConfigName(mixed $name): ?string
    {
        if (!is_string($name)) {
            return null;
        }

        $normalized = trim($name);

        return $normalized !== '' ? $normalized : null;
    }

    protected function turnProcessor(): AutonomousCollectorTurnProcessor
    {
        return $this->turnProcessor ??= new AutonomousCollectorTurnProcessor(
            ai: $this->ai,
            promptBuilder: new CollectorPromptBuilder($this->locale()),
            parser: new CollectorToolCallParser(),
            toolExecution: new CollectorToolExecutionService(),
            confirmation: new CollectorConfirmationService($this->locale()),
            summaryRenderer: new CollectorSummaryRenderer(),
            inputSchemaBuilder: new CollectorInputSchemaBuilder($this->locale()),
            reroutePolicy: new CollectorReroutePolicy($this->locale()),
            localeResources: $this->locale(),
        );
    }

    protected function locale(): LocaleResourceService
    {
        if ($this->localeResources === null) {
            $this->localeResources = app(LocaleResourceService::class);
        }

        return $this->localeResources;
    }
}
