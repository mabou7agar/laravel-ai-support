<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Contracts\ActionFlowHandler;
use LaravelAIEngine\Services\Actions\ActionDraftService;
use LaravelAIEngine\Services\Actions\ActionPayloadExtractor;
use LaravelAIEngine\Services\Actions\ActionReplyGeneratorService;

class UpdateActionDraftTool extends AgentTool
{
    public function __construct(
        private readonly ActionDraftService $drafts,
        private readonly ActionFlowHandler $actions,
        private readonly ActionPayloadExtractor $payloadExtractor,
        private readonly ActionReplyGeneratorService $replies
    ) {
    }

    public function getName(): string
    {
        return 'update_action_draft';
    }

    public function getDescription(): string
    {
        return 'Patch the current session action draft, validate it, and return missing fields, relation review, next options, or a confirmation-ready draft. It never writes application data.';
    }

    public function getParameters(): array
    {
        return [
            'action_id' => ['type' => 'string', 'required' => true],
            'payload_patch' => ['type' => 'object', 'required' => true],
            'reset' => ['type' => 'boolean', 'required' => false],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $actionId = (string) ($parameters['action_id'] ?? '');
        $payloadPatch = (array) ($parameters['payload_patch'] ?? []);
        $payloadPatch = $this->completePayloadPatch($actionId, $payloadPatch, $context);

        $result = $this->drafts->patchAndPrepare(
            $context,
            $actionId,
            $payloadPatch,
            (bool) ($parameters['reset'] ?? false)
        );

        if (($result['success'] ?? false) && !$this->requiresRelationInput($result)) {
            $reply = $this->reply($result);

            return ActionResult::success(
                $reply['text'],
                $result,
                ['agent_strategy' => 'action_prepare'] + $reply['metadata']
            );
        }

        $reply = $this->reply($result);

        return ActionResult::needsUserInput(
            $reply['text'],
            $result,
            ['agent_strategy' => 'action_needs_input'] + $reply['metadata']
        );
    }

    /**
     * @param array<string, mixed> $actionResult
     * @return array{text:string,metadata:array<string,mixed>}
     */
    private function reply(array $actionResult): array
    {
        return $this->replies->generate($actionResult);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function requiresRelationInput(array $result): bool
    {
        return collect($result['next_options'] ?? [])
            ->contains(fn (mixed $option): bool => is_array($option) && ($option['type'] ?? null) === 'relation_create_confirmation');
    }

    /**
     * @param array<string, mixed> $payloadPatch
     * @return array<string, mixed>
     */
    private function completePayloadPatch(string $actionId, array $payloadPatch, UnifiedActionContext $context): array
    {
        $message = $this->latestUserMessage($context);
        if ($actionId === '' || $message === '') {
            return $payloadPatch;
        }

        $action = $this->actions->action($actionId, $context);
        if (!is_array($action)) {
            return $payloadPatch;
        }

        $currentPayload = $this->drafts->get($context, $actionId);
        $extracted = $this->payloadExtractor->extract(
            action: $action,
            message: $message,
            currentPayload: $currentPayload,
            recentHistory: $context->conversationHistory,
            options: [
                'instructions' => implode("\n", [
                    'The user is continuing an existing action draft through update_action_draft.',
                    'Return a payload patch for the latest user message.',
                    'If the message confirms a pending relation create, return approved_missing_relations using the matching approval key from current action facts.',
                    'If the message corrects a field, return the corrected field value.',
                    'Current action facts: ' . json_encode([
                        'current_payload' => $currentPayload,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]),
            ]
        );

        return is_array($extracted) && $extracted !== []
            ? array_replace_recursive($payloadPatch, $extracted)
            : $payloadPatch;
    }

    private function latestUserMessage(UnifiedActionContext $context): string
    {
        if (is_string($context->metadata['latest_user_message'] ?? null)) {
            return trim($context->metadata['latest_user_message']);
        }

        $messages = array_reverse($context->conversationHistory);
        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'user' && is_string($message['content'] ?? null)) {
                return trim($message['content']);
            }
        }

        return '';
    }
}
