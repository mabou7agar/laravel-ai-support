<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Handlers;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\DTOs\AutonomousCollectorSessionState;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Collectors\AutonomousCollectorTurnProcessor;
use LaravelAIEngine\Services\Agent\Collectors\CollectorConfigResolver;
use LaravelAIEngine\Services\Agent\Collectors\CollectorConfirmationService;
use LaravelAIEngine\Services\Agent\Collectors\CollectorInputSchemaBuilder;
use LaravelAIEngine\Services\Agent\Collectors\CollectorPromptBuilder;
use LaravelAIEngine\Services\Agent\Collectors\CollectorReroutePolicy;
use LaravelAIEngine\Services\Agent\Collectors\CollectorSummaryRenderer;
use LaravelAIEngine\Services\Agent\Collectors\CollectorToolCallParser;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorSessionService;
use LaravelAIEngine\Services\Localization\LocaleResourceService;

class AutonomousCollectorHandler implements MessageHandlerInterface
{
    public function __construct(
        protected AutonomousCollectorSessionService $collectorService,
        protected ?LocaleResourceService $localeResources = null,
        protected ?CollectorConfigResolver $configResolver = null,
        protected ?AutonomousCollectorTurnProcessor $turnProcessor = null,
        protected ?CollectorConfirmationService $confirmationService = null,
        protected ?CollectorSummaryRenderer $summaryRenderer = null,
        protected ?CollectorInputSchemaBuilder $inputSchemaBuilder = null,
        protected ?CollectorPromptBuilder $promptBuilder = null,
        protected ?CollectorToolCallParser $parser = null,
        protected ?CollectorReroutePolicy $reroutePolicy = null,
    ) {
    }

    public function handle(
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse {
        $action = $options['action'] ?? 'continue_autonomous_collector';

        Log::channel('ai-engine')->info('AutonomousCollectorHandler processing', [
            'session_id' => $context->sessionId,
            'action' => $action,
            'message' => substr($message, 0, 100),
        ]);

        if ($action === 'start_autonomous_collector') {
            return $this->handleStartCollector($message, $context, $options);
        }

        $rawState = $context->get('autonomous_collector');
        if (!is_array($rawState)) {
            Log::channel('ai-engine')->warning('AutonomousCollectorHandler called without active collector');

            return AgentResponse::failure(
                message: $this->locale()->translation('ai-engine::runtime.autonomous_collector.no_active_session')
                    ?: 'No active collector session.',
                context: $context
            );
        }

        $state = AutonomousCollectorSessionState::fromArray($rawState);
        $config = $this->configResolver()->resolve($state);

        if (!$config instanceof AutonomousCollectorConfig) {
            Log::channel('ai-engine')->error('Collector config not found', [
                'config_name' => $state->configName,
            ]);

            return AgentResponse::failure(
                message: $this->locale()->translation('ai-engine::runtime.autonomous_collector.config_not_found')
                    ?: 'Collector configuration not found.',
                context: $context
            );
        }

        return $this->turnProcessor()->process($context->sessionId, $message, $config, $state, $context);
    }

    protected function handleStartCollector(string $message, UnifiedActionContext $context, array $options = []): AgentResponse
    {
        $match = $this->configResolver()->resolveStartMatch($message, $options['collector_match'] ?? null);

        if (!$match || !($match['config'] ?? null) instanceof AutonomousCollectorConfig) {
            Log::channel('ai-engine')->warning('No autonomous collector config found for message', [
                'message' => substr($message, 0, 100),
            ]);

            return AgentResponse::conversational(
                message: $this->locale()->translation('ai-engine::runtime.autonomous_collector.no_matching_collector')
                    ?: "I couldn't find a matching collector for your request. Can you please clarify what you'd like to do?",
                context: $context
            );
        }

        return $this->startCollector(
            context: $context,
            config: $match['config'],
            initialMessage: $message,
            configNameHint: (string) ($match['name'] ?? '')
        );
    }

    public function startCollector(
        UnifiedActionContext $context,
        AutonomousCollectorConfig $config,
        string $initialMessage = '',
        string $configNameHint = ''
    ): AgentResponse {
        $configName = $this->configResolver()->register($config, $context, $configNameHint);

        $state = new AutonomousCollectorSessionState(
            configName: $configName,
            status: AutonomousCollectorSessionState::STATUS_COLLECTING,
        );

        $context->set('autonomous_collector', $state->toArray());

        Log::channel('ai-engine')->info('Autonomous collector started', [
            'session_id' => $context->sessionId,
            'config_name' => $configName,
            'goal' => $config->goal,
        ]);

        if ($initialMessage !== '') {
            return $this->handle($initialMessage, $context);
        }

        $greeting = "Hello! I'll help you {$config->goal}. What would you like to do?";
        $state->appendConversation('assistant', $greeting);
        $context->set('autonomous_collector', $state->toArray());

        return AgentResponse::needsUserInput(
            message: $greeting,
            context: $context
        );
    }

    public function canHandle(string $action): bool
    {
        return in_array($action, ['continue_autonomous_collector', 'start_autonomous_collector'], true);
    }

    protected function generateSummary(
        array $data,
        int $depth = 0,
        ?AutonomousCollectorConfig $config = null,
        array $toolResults = []
    ): string {
        return $this->summaryRenderer()->generateSummary($data, $depth, $config, $toolResults);
    }

    protected function buildSuccessMessage(mixed $result, array $collectedData, AutonomousCollectorConfig $config): string
    {
        return $this->summaryRenderer()->buildSuccessMessage($result, $collectedData, $config);
    }

    protected function requiresToolConfirmation(AutonomousCollectorConfig $config, string $toolName): bool
    {
        return $this->confirmationService()->requiresToolConfirmation($config, $toolName);
    }

    protected function isConfirmedToolCall(string $toolName, array $conversation): bool
    {
        return $this->confirmationService()->isConfirmedToolCall($toolName, $conversation);
    }

    protected function buildToolConfirmationMessage(string $toolName, array $arguments, AutonomousCollectorConfig $config): string
    {
        return $this->confirmationService()->buildToolConfirmationMessage($toolName, $arguments, $config);
    }

    protected function extractFinalOutput(string $message): ?array
    {
        return $this->parser()->extractFinalOutput($message);
    }

    protected function buildConversationPrompt(array $conversation): string
    {
        return $this->promptBuilder()->buildConversationPrompt($conversation);
    }

    protected function extractRequiredInputs(string $content, AutonomousCollectorConfig $config): ?array
    {
        return $this->inputSchemaBuilder()->extractRequiredInputs($content, $config);
    }

    protected function isUnrelatedQuery(string $message, AutonomousCollectorConfig $config): bool
    {
        return $this->reroutePolicy()->shouldExitForMessage($message, $config);
    }

    protected function configResolver(): CollectorConfigResolver
    {
        return $this->configResolver ??= new CollectorConfigResolver($this->collectorService);
    }

    protected function turnProcessor(): AutonomousCollectorTurnProcessor
    {
        return $this->turnProcessor ??= app(AutonomousCollectorTurnProcessor::class);
    }

    protected function confirmationService(): CollectorConfirmationService
    {
        return $this->confirmationService ??= new CollectorConfirmationService($this->locale());
    }

    protected function summaryRenderer(): CollectorSummaryRenderer
    {
        return $this->summaryRenderer ??= new CollectorSummaryRenderer();
    }

    protected function inputSchemaBuilder(): CollectorInputSchemaBuilder
    {
        return $this->inputSchemaBuilder ??= new CollectorInputSchemaBuilder($this->locale());
    }

    protected function promptBuilder(): CollectorPromptBuilder
    {
        return $this->promptBuilder ??= new CollectorPromptBuilder($this->locale());
    }

    protected function parser(): CollectorToolCallParser
    {
        return $this->parser ??= new CollectorToolCallParser();
    }

    protected function reroutePolicy(): CollectorReroutePolicy
    {
        return $this->reroutePolicy ??= new CollectorReroutePolicy($this->locale());
    }

    protected function locale(): LocaleResourceService
    {
        if ($this->localeResources === null) {
            $this->localeResources = app(LocaleResourceService::class);
        }

        return $this->localeResources;
    }
}
