<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Enums\ActionTypeEnum;
use Illuminate\Support\Facades\Log;

/**
 * Smart Action Service
 * 
 * Generates executable actions with AI-powered parameter extraction.
 * Actions are pre-filled with all required data extracted from context.
 */
class SmartActionService
{
    protected array $actionDefinitions = [];

    public function __construct(
        protected ?AIEngineService $aiService = null
    ) {
        $this->registerDefaultActions();
    }

    /**
     * Register default action definitions
     */
    protected function registerDefaultActions(): void
    {
        // Email Reply Action
        $this->registerAction('reply_email', [
            'label' => '✉️ Reply to Email',
            'description' => 'Draft and send a reply to this email',
            'context_triggers' => ['email', 'inbox', 'message', 'from:', 'subject:'],
            'required_params' => ['to_email', 'subject', 'original_content'],
            'optional_params' => ['draft_body', 'cc', 'bcc'],
            'extractor' => function ($content, $sources, $metadata) {
                return $this->extractEmailParams($content, $sources, $metadata);
            },
            'executor' => 'email.reply',
        ]);

        // Forward Email Action
        $this->registerAction('forward_email', [
            'label' => '↗️ Forward Email',
            'description' => 'Forward this email to someone',
            'context_triggers' => ['email', 'forward', 'share'],
            'required_params' => ['original_subject', 'original_content'],
            'optional_params' => ['to_email', 'note'],
            'extractor' => function ($content, $sources, $metadata) {
                return $this->extractForwardParams($content, $sources, $metadata);
            },
            'executor' => 'email.forward',
        ]);

        // Create Calendar Event
        $this->registerAction('create_event', [
            'label' => '📅 Create Calendar Event',
            'description' => 'Add this to your calendar',
            'context_triggers' => ['meeting', 'schedule', 'calendar', 'appointment', 'call', 'tomorrow', 'next week'],
            'required_params' => ['title'],
            'optional_params' => ['date', 'time', 'duration', 'location', 'attendees', 'description'],
            'extractor' => function ($content, $sources, $metadata) {
                return $this->extractCalendarParams($content, $sources, $metadata);
            },
            'executor' => 'calendar.create',
        ]);

        // Create Task
        $this->registerAction('create_task', [
            'label' => '✅ Create Task',
            'description' => 'Create a task from this content',
            'context_triggers' => ['task', 'todo', 'reminder', 'follow up', 'action item', 'deadline'],
            'required_params' => ['title'],
            'optional_params' => ['due_date', 'priority', 'description', 'assignee'],
            'extractor' => function ($content, $sources, $metadata) {
                return $this->extractTaskParams($content, $sources, $metadata);
            },
            'executor' => 'task.create',
        ]);

        // Summarize Content
        $this->registerAction('summarize', [
            'label' => '📝 Summarize',
            'description' => 'Get a summary of this content',
            'context_triggers' => ['long', 'detailed', 'article', 'document', 'report'],
            'required_params' => ['content'],
            'optional_params' => ['length', 'format'],
            'extractor' => function ($content, $sources, $metadata) {
                return ['content' => $content, 'length' => 'brief'];
            },
            'executor' => 'ai.summarize',
        ]);

        // Translate Content
        $this->registerAction('translate', [
            'label' => '🌐 Translate',
            'description' => 'Translate this content',
            'context_triggers' => [],
            'required_params' => ['content'],
            'optional_params' => ['target_language', 'source_language'],
            'extractor' => function ($content, $sources, $metadata) {
                return ['content' => $content, 'target_language' => 'en'];
            },
            'executor' => 'ai.translate',
        ]);
    }

    /**
     * Register a custom action
     */
    public function registerAction(string $id, array $definition): void
    {
        $this->actionDefinitions[$id] = array_merge([
            'id' => $id,
            'label' => $id,
            'description' => '',
            'context_triggers' => [],
            'required_params' => [],
            'optional_params' => [],
            'extractor' => null,
            'executor' => null,
        ], $definition);
    }

    /**
     * Generate smart actions based on content and context
     */
    public function generateSmartActions(
        string $content,
        array $sources = [],
        array $metadata = []
    ): array {
        $actions = [];

        foreach ($this->actionDefinitions as $id => $definition) {
            // Check if any context triggers match
            if ($this->matchesTriggers($content, $definition['context_triggers'])) {
                // Extract parameters using the extractor
                $params = [];
                if (is_callable($definition['extractor'])) {
                    $params = call_user_func($definition['extractor'], $content, $sources, $metadata);
                }

                // Only add action if required params can be extracted
                if ($this->hasRequiredParams($params, $definition['required_params'])) {
                    $actions[] = new InteractiveAction(
                        id: $id . '_' . uniqid(),
                        type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
                        label: $definition['label'],
                        description: $definition['description'],
                        data: [
                            'action' => $id,
                            'executor' => $definition['executor'],
                            'params' => $params,
                            'ready_to_execute' => true,
                        ]
                    );
                }
            }
        }

        return $actions;
    }

    /**
     * Check if content matches any triggers
     */
    protected function matchesTriggers(string $content, array $triggers): bool
    {
        if (empty($triggers)) {
            return false;
        }

        $contentLower = strtolower($content);
        foreach ($triggers as $trigger) {
            if (stripos($contentLower, strtolower($trigger)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if all required params are present
     */
    protected function hasRequiredParams(array $params, array $required): bool
    {
        foreach ($required as $param) {
            if (!isset($params[$param]) || empty($params[$param])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Extract email parameters from content and sources
     */
    protected function extractEmailParams(string $content, array $sources, array $metadata): array
    {
        $params = [];

        // Try to extract from sources (RAG results)
        foreach ($sources as $source) {
            if (isset($source['metadata'])) {
                $meta = $source['metadata'];
                if (isset($meta['from_email'])) {
                    $params['to_email'] = $meta['from_email'];
                }
                if (isset($meta['subject'])) {
                    $params['subject'] = 'Re: ' . $meta['subject'];
                }
                if (isset($meta['content']) || isset($meta['body'])) {
                    $params['original_content'] = $meta['content'] ?? $meta['body'];
                }
            }
        }

        // Extract email from content using regex
        if (empty($params['to_email'])) {
            if (preg_match('/[\w\.-]+@[\w\.-]+\.\w+/', $content, $matches)) {
                $params['to_email'] = $matches[0];
            }
        }

        // Extract subject from content
        if (empty($params['subject'])) {
            if (preg_match('/subject[:\s]+([^\n]+)/i', $content, $matches)) {
                $params['subject'] = 'Re: ' . trim($matches[1]);
            }
        }

        // Use AI to generate draft reply if available
        if ($this->aiService && !empty($params['original_content'])) {
            try {
                $params['draft_body'] = $this->generateDraftReply($params['original_content']);
            } catch (\Exception $e) {
                Log::warning('Failed to generate draft reply: ' . $e->getMessage());
            }
        }

        return $params;
    }

    /**
     * Extract forward parameters
     */
    protected function extractForwardParams(string $content, array $sources, array $metadata): array
    {
        $params = [];

        foreach ($sources as $source) {
            if (isset($source['metadata'])) {
                $meta = $source['metadata'];
                if (isset($meta['subject'])) {
                    $params['original_subject'] = 'Fwd: ' . $meta['subject'];
                }
                if (isset($meta['content']) || isset($meta['body'])) {
                    $params['original_content'] = $meta['content'] ?? $meta['body'];
                }
            }
        }

        return $params;
    }

    /**
     * Extract calendar event parameters
     */
    protected function extractCalendarParams(string $content, array $sources, array $metadata): array
    {
        $params = [];

        // Extract date/time patterns
        $datePatterns = [
            '/(\d{1,2}\/\d{1,2}\/\d{2,4})/' => 'date',
            '/(\d{4}-\d{2}-\d{2})/' => 'date',
            '/(\d{1,2}:\d{2}\s*(?:am|pm)?)/i' => 'time',
            '/tomorrow/i' => 'date_relative',
            '/next\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)/i' => 'date_relative',
        ];

        foreach ($datePatterns as $pattern => $type) {
            if (preg_match($pattern, $content, $matches)) {
                if ($type === 'date') {
                    $params['date'] = $matches[1];
                } elseif ($type === 'time') {
                    $params['time'] = $matches[1];
                } elseif ($type === 'date_relative') {
                    $params['date'] = $this->parseRelativeDate($matches[0]);
                }
            }
        }

        // Extract title from content (first line or meeting keyword context)
        if (preg_match('/meeting\s+(?:about|for|with|on)\s+([^\n\.]+)/i', $content, $matches)) {
            $params['title'] = trim($matches[1]);
        } elseif (preg_match('/schedule\s+([^\n\.]+)/i', $content, $matches)) {
            $params['title'] = trim($matches[1]);
        } else {
            // Use first sentence as title
            $params['title'] = strtok($content, ".\n");
        }

        // Extract duration
        if (preg_match('/(\d+)\s*(?:hour|hr|minute|min)/i', $content, $matches)) {
            $params['duration'] = $matches[0];
        }

        // Extract attendees (email addresses)
        preg_match_all('/[\w\.-]+@[\w\.-]+\.\w+/', $content, $emailMatches);
        if (!empty($emailMatches[0])) {
            $params['attendees'] = array_unique($emailMatches[0]);
        }

        return $params;
    }

    /**
     * Extract task parameters
     */
    protected function extractTaskParams(string $content, array $sources, array $metadata): array
    {
        $params = [];

        // Extract title
        if (preg_match('/(?:task|todo|reminder)[:\s]+([^\n\.]+)/i', $content, $matches)) {
            $params['title'] = trim($matches[1]);
        } else {
            $params['title'] = strtok($content, ".\n");
        }

        // Extract due date
        if (preg_match('/(?:due|by|deadline)[:\s]+([^\n\.]+)/i', $content, $matches)) {
            $params['due_date'] = trim($matches[1]);
        }

        // Extract priority
        if (preg_match('/(?:urgent|high\s*priority|important)/i', $content)) {
            $params['priority'] = 'high';
        } elseif (preg_match('/(?:low\s*priority)/i', $content)) {
            $params['priority'] = 'low';
        } else {
            $params['priority'] = 'normal';
        }

        $params['description'] = $content;

        return $params;
    }

    /**
     * Parse relative date strings
     */
    protected function parseRelativeDate(string $relative): string
    {
        $relative = strtolower($relative);

        if ($relative === 'tomorrow') {
            return date('Y-m-d', strtotime('+1 day'));
        }

        if (preg_match('/next\s+(\w+)/', $relative, $matches)) {
            return date('Y-m-d', strtotime('next ' . $matches[1]));
        }

        return date('Y-m-d');
    }

    /**
     * Generate a draft reply using AI
     */
    protected function generateDraftReply(string $originalContent): string
    {
        if (!$this->aiService) {
            return '';
        }

        $prompt = "Generate a brief, professional reply to this email. Keep it concise:\n\n" . $originalContent;

        try {
            $response = $this->aiService->generate(
                new \LaravelAIEngine\DTOs\AIRequest(
                    prompt: $prompt,
                    engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                    model: \LaravelAIEngine\Enums\EntityEnum::from('gpt-4o-mini'),
                    parameters: ['max_tokens' => 200]
                )
            );

            return $response->getContent();
        } catch (\Exception $e) {
            Log::warning('Failed to generate draft reply: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Get all registered action definitions
     */
    public function getActionDefinitions(): array
    {
        return $this->actionDefinitions;
    }

    /**
     * Get a specific action definition
     */
    public function getActionDefinition(string $id): ?array
    {
        return $this->actionDefinitions[$id] ?? null;
    }
}
