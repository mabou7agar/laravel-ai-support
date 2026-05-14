<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Collectors;

use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\DTOs\AutonomousCollectorSessionState;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Localization\LocaleResourceService;

class CollectorPromptBuilder
{
    public function __construct(
        protected ?LocaleResourceService $localeResources = null,
    ) {
    }

    public function buildSystemPrompt(
        AutonomousCollectorConfig $config,
        AutonomousCollectorSessionState $state,
        UnifiedActionContext $context
    ): string {
        $prompt = $config->buildSystemPrompt();

        if ($context->conversationHistory !== []) {
            $prompt .= "\n\n## Prior Conversation Context\n";
            $prompt .= "The following conversation happened before this task started:\n";
            foreach (array_slice($context->conversationHistory, -10) as $message) {
                $role = ucfirst((string) ($message['role'] ?? 'unknown'));
                $content = $this->truncate((string) ($message['content'] ?? ''), 500);
                $prompt .= "**{$role}:** {$content}\n";
            }
            $prompt .= "\nUse this context to understand what the user is referring to.\n";
        }

        if ($state->toolResults !== []) {
            $prompt .= "\n\n## Recent Tool Results\n";
            foreach (array_slice($state->toolResults, -5) as $result) {
                $toolName = (string) ($result['tool'] ?? 'unknown');
                $toolResult = $result['result'] ?? $result['error'] ?? $result;
                $encoded = json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $prompt .= "- {$toolName}: " . $this->truncate((string) $encoded, 1000) . "\n";
            }
        }

        return $prompt;
    }

    public function buildConversationPrompt(array $conversation): string
    {
        $prompt = '';

        foreach ($conversation as $message) {
            $role = ucfirst((string) ($message['role'] ?? 'unknown'));
            $content = (string) ($message['content'] ?? '');
            $prompt .= "{$role}: {$content}\n\n";
        }

        return $prompt;
    }

    public function buildUserPrompt(AutonomousCollectorConfig $config, array $conversation): string
    {
        $prompt = $this->buildConversationPrompt($conversation);

        if ($config->getToolDefinitions() === []) {
            return $prompt;
        }

        return $prompt . "\n\n---\n" . $this->toolProtocol();
    }

    protected function toolProtocol(): string
    {
        $locale = $this->locale();
        if (method_exists($locale, 'renderPromptTemplate')) {
            $template = $locale->renderPromptTemplate('autonomous_collector/tool_protocol');
            if (is_string($template) && trim($template) !== '') {
                return $template;
            }
        }

        return "If you need to use a tool, respond with:\n"
            . "```tool\n{\"tool\": \"tool_name\", \"arguments\": {...}}\n```\n"
            . "Otherwise, respond naturally to the user.\n"
            . "When you have all required information, output the final data as:\n"
            . "```json\n{...final output...}\n```";
    }

    protected function truncate(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) . '...' : $value;
    }

    protected function locale(): LocaleResourceService
    {
        if ($this->localeResources === null) {
            $this->localeResources = app(LocaleResourceService::class);
        }

        return $this->localeResources;
    }
}
