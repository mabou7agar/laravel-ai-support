<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Collectors;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\DTOs\AutonomousCollectorSessionState;
use LaravelAIEngine\DTOs\CollectorToolCall;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Localization\LocaleResourceService;

class AutonomousCollectorTurnProcessor
{
    protected int $maxToolLoops = 8;
    protected int $maxValidationRetries = 3;

    public function __construct(
        protected AIEngineService $ai,
        protected CollectorPromptBuilder $promptBuilder,
        protected CollectorToolCallParser $parser,
        protected CollectorToolExecutionService $toolExecution,
        protected CollectorConfirmationService $confirmation,
        protected CollectorSummaryRenderer $summaryRenderer,
        protected CollectorInputSchemaBuilder $inputSchemaBuilder,
        protected CollectorReroutePolicy $reroutePolicy,
        protected ?LocaleResourceService $localeResources = null,
    ) {
    }

    public function process(
        string $sessionId,
        string $message,
        AutonomousCollectorConfig $config,
        AutonomousCollectorSessionState $state,
        UnifiedActionContext $context
    ): AgentResponse {
        if ($state->status === AutonomousCollectorSessionState::STATUS_CONFIRMING) {
            return $this->processFinalConfirmation($sessionId, $message, $config, $state, $context);
        }

        if ($state->pendingToolConfirmation !== null) {
            return $this->processPendingToolConfirmation($message, $config, $state, $context);
        }

        if ($this->confirmation->isCancellation($message)) {
            $context->forget('autonomous_collector');

            return AgentResponse::success(
                message: $this->locale()->translation('ai-engine::runtime.autonomous_collector.cancelled') ?: 'Collection cancelled.',
                context: $context
            );
        }

        if ($this->reroutePolicy->shouldExitForMessage($message, $config)) {
            Log::channel('ai-engine')->info('Autonomous collector exiting for reroute', [
                'session_id' => $sessionId,
                'collector' => $config->name,
                'message' => $message,
            ]);
            $context->forget('autonomous_collector');

            return AgentResponse::failure(
                message: 'exit_and_reroute',
                data: ['reroute_message' => $message],
                context: $context
            );
        }

        $state->turnCount++;
        $state->resetLoopCounters();

        return $this->generateAIResponse($message, $config, $state, $context);
    }

    protected function processFinalConfirmation(
        string $sessionId,
        string $message,
        AutonomousCollectorConfig $config,
        AutonomousCollectorSessionState $state,
        UnifiedActionContext $context
    ): AgentResponse {
        if ($this->confirmation->isConfirmation($message)) {
            try {
                $result = $config->executeOnComplete($state->collectedData);
                $context->forget('autonomous_collector');

                Log::channel('ai-engine')->info('Autonomous collector completed', [
                    'session_id' => $sessionId,
                    'data' => $state->collectedData,
                ]);

                return AgentResponse::success(
                    message: $this->summaryRenderer->buildSuccessMessage($result, $state->collectedData, $config),
                    data: ['result' => $result, 'collected_data' => $state->collectedData],
                    context: $context
                );
            } catch (\Throwable $exception) {
                Log::channel('ai-engine')->error('Autonomous collector completion failed', [
                    'error' => $exception->getMessage(),
                ]);

                return AgentResponse::failure(
                    message: $this->locale()->translation(
                        'ai-engine::runtime.autonomous_collector.completion_failed',
                        ['error' => $exception->getMessage()]
                    ) ?: "Failed to complete: {$exception->getMessage()}",
                    context: $context
                );
            }
        }

        if ($this->confirmation->isDenial($message)) {
            $state->status = AutonomousCollectorSessionState::STATUS_COLLECTING;
            $context->set('autonomous_collector', $state->toArray());

            return AgentResponse::needsUserInput(
                message: $this->locale()->translation('ai-engine::runtime.autonomous_collector.change_prompt')
                    ?: 'No problem. What would you like to change?',
                context: $context
            );
        }

        return AgentResponse::needsUserInput(
            message: $this->locale()->translation('ai-engine::runtime.autonomous_collector.confirm_type_hint')
                ?: 'Please confirm or tell me what to change.',
            context: $context
        );
    }

    protected function processPendingToolConfirmation(
        string $message,
        AutonomousCollectorConfig $config,
        AutonomousCollectorSessionState $state,
        UnifiedActionContext $context
    ): AgentResponse {
        $pendingTool = CollectorToolCall::fromArray($state->pendingToolConfirmation ?? []);
        if (!$pendingTool instanceof CollectorToolCall) {
            $state->pendingToolConfirmation = null;
            $context->set('autonomous_collector', $state->toArray());

            return $this->generateAIResponse($message, $config, $state, $context);
        }

        if ($this->confirmation->isConfirmation($message)) {
            $state->pendingToolConfirmation = null;
            $state->appendConversation('user', $message);

            return $this->handleToolCall($pendingTool, $config, $state, $context, true);
        }

        if ($this->confirmation->isDenial($message)) {
            $state->pendingToolConfirmation = null;
            $state->appendConversation('user', $message);
            $state->appendConversation('assistant', 'No problem. What would you like to change?');
            $context->set('autonomous_collector', $state->toArray());

            return AgentResponse::needsUserInput(
                message: $this->locale()->translation('ai-engine::runtime.autonomous_collector.change_prompt')
                    ?: 'No problem. What would you like to change?',
                context: $context
            );
        }

        $context->set('autonomous_collector', $state->toArray());

        return AgentResponse::needsUserInput(
            message: $this->confirmation->buildToolConfirmationMessage($pendingTool->tool, $pendingTool->arguments, $config),
            data: ['pending_tool_confirmation' => $pendingTool->toArray()],
            context: $context
        );
    }

    protected function generateAIResponse(
        string $message,
        AutonomousCollectorConfig $config,
        AutonomousCollectorSessionState $state,
        UnifiedActionContext $context
    ): AgentResponse {
        if ($message !== '') {
            $state->appendConversation('user', $message);
        }

        if ($state->turnCount > $config->maxTurns) {
            $context->set('autonomous_collector', $state->toArray());

            return AgentResponse::needsUserInput(
                message: 'I need a confirmation or a clearer instruction before continuing this task.',
                context: $context
            );
        }

        try {
            $response = $this->ai->generate(new AIRequest(
                prompt: $this->promptBuilder->buildUserPrompt($config, $state->conversation),
                systemPrompt: $this->promptBuilder->buildSystemPrompt($config, $state, $context),
                maxTokens: 1500,
                temperature: 0.7,
                functions: $config->getToolDefinitions(),
            ));

            $content = $response->getContent();
            $toolCall = $this->parser->extractToolCallFromResponse($response);
            if ($toolCall instanceof CollectorToolCall) {
                return $this->handleToolCall($toolCall, $config, $state, $context);
            }

            $finalOutput = $this->parser->extractFinalOutput($content);
            if ($finalOutput !== null) {
                return $this->handleFinalOutput($finalOutput, $content, $config, $state, $context);
            }

            $state->appendConversation('assistant', $content);
            $context->set('autonomous_collector', $state->toArray());

            return AgentResponse::needsUserInput(
                message: $content,
                context: $context,
                requiredInputs: $this->inputSchemaBuilder->extractRequiredInputs($content, $config)
            );
        } catch (\Throwable $exception) {
            Log::channel('ai-engine')->error('Autonomous collector AI generation failed', [
                'error' => $exception->getMessage(),
            ]);

            return AgentResponse::failure(
                message: 'I encountered an error. Please try again.',
                context: $context
            );
        }
    }

    protected function handleToolCall(
        CollectorToolCall $toolCall,
        AutonomousCollectorConfig $config,
        AutonomousCollectorSessionState $state,
        UnifiedActionContext $context,
        bool $confirmationSatisfied = false
    ): AgentResponse {
        if (
            !$confirmationSatisfied
            && $this->confirmation->requiresToolConfirmation($config, $toolCall->tool)
            && !$this->confirmation->isConfirmedToolCall($toolCall->tool, $state->conversation)
        ) {
            $message = $this->confirmation->buildToolConfirmationMessage($toolCall->tool, $toolCall->arguments, $config);
            $state->appendConversation('assistant', $message);
            $state->pendingToolConfirmation = $toolCall->toArray();
            $context->set('autonomous_collector', $state->toArray());

            return AgentResponse::needsUserInput(
                message: $message,
                data: ['pending_tool_confirmation' => $toolCall->toArray()],
                context: $context
            );
        }

        if ($state->incrementToolLoop() > $this->maxToolLoops) {
            $context->set('autonomous_collector', $state->toArray());

            return AgentResponse::failure(
                message: 'Tool execution stopped because the collector exceeded its tool loop limit.',
                context: $context
            );
        }

        $result = $this->toolExecution->execute($toolCall, $config, $context);
        $state->toolResults[] = $result->toArray();

        $payload = $result->result ?? $result->error;
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $state->appendConversation('system', "Tool {$toolCall->tool} result: " . mb_substr((string) $encoded, 0, 500));
        $context->set('autonomous_collector', $state->toArray());

        return $this->generateAIResponse('', $config, $state, $context);
    }

    protected function handleFinalOutput(
        array $finalOutput,
        string $aiMessage,
        AutonomousCollectorConfig $config,
        AutonomousCollectorSessionState $state,
        UnifiedActionContext $context
    ): AgentResponse {
        $errors = $config->validateOutput($finalOutput);
        if ($errors !== []) {
            if ($state->incrementValidationRetry() > $this->maxValidationRetries) {
                $context->set('autonomous_collector', $state->toArray());

                return AgentResponse::failure(
                    message: 'Output validation failed too many times: ' . implode(', ', $errors),
                    context: $context
                );
            }

            $state->appendConversation('system', 'Output validation failed: ' . implode(', ', $errors));
            $context->set('autonomous_collector', $state->toArray());

            return $this->generateAIResponse('', $config, $state, $context);
        }

        $state->collectedData = $finalOutput;
        $state->status = $config->confirmBeforeComplete
            ? AutonomousCollectorSessionState::STATUS_CONFIRMING
            : AutonomousCollectorSessionState::STATUS_COMPLETED;

        if ($config->confirmBeforeComplete) {
            $message = $this->buildConfirmationReview($finalOutput, $config, $state);
            $state->appendConversation('assistant', $message);
            $context->set('autonomous_collector', $state->toArray());

            return AgentResponse::needsUserInput(
                message: $message,
                data: ['collected_data' => $finalOutput, 'requires_confirmation' => true],
                context: $context
            );
        }

        try {
            $result = $config->executeOnComplete($finalOutput);
            $context->forget('autonomous_collector');

            return AgentResponse::success(
                message: '✅ ' . ($this->locale()->translation('ai-engine::runtime.autonomous_collector.completed') ?: 'Successfully completed!'),
                data: ['result' => $result],
                context: $context
            );
        } catch (\Throwable $exception) {
            return AgentResponse::failure(
                message: $this->locale()->translation(
                    'ai-engine::runtime.autonomous_collector.failed',
                    ['error' => $exception->getMessage()]
                ) ?: "Failed: {$exception->getMessage()}",
                context: $context
            );
        }
    }

    protected function buildConfirmationReview(
        array $finalOutput,
        AutonomousCollectorConfig $config,
        AutonomousCollectorSessionState $state
    ): string {
        $summary = $this->summaryRenderer->generateSummary($finalOutput, 0, $config, $state->toolResults);
        $yesToken = $this->locale()->lexicon('intent.confirm', default: ['yes'])[0] ?? 'yes';
        $noToken = $this->locale()->lexicon('intent.reject', default: ['no'])[0] ?? 'no';
        $title = $this->locale()->translation('ai-engine::runtime.autonomous_collector.confirm_review_title') ?: 'Please Review:';
        $footer = $this->locale()->translation('ai-engine::runtime.autonomous_collector.confirm_footer') ?: 'Confirm to proceed or cancel';
        $typeHint = $this->locale()->translation(
            'ai-engine::runtime.autonomous_collector.confirm_type_hint',
            ['yes' => $yesToken, 'no' => $noToken]
        ) ?: "Type: '{$yesToken}' or '{$noToken}'";

        return "📋 **{$title}**\n\n{$summary}\n\n---\n✅ {$footer}\n{$typeHint}";
    }

    protected function locale(): LocaleResourceService
    {
        if ($this->localeResources === null) {
            $this->localeResources = app(LocaleResourceService::class);
        }

        return $this->localeResources;
    }
}
