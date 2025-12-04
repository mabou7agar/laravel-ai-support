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

        // Create Task/Todo
        $this->registerAction('create_task', [
            'label' => '✅ Create Task',
            'description' => 'Add this as a task',
            'context_triggers' => ['task', 'todo', 'reminder', 'deadline', 'due', 'action item'],
            'required_params' => ['title'],
            'optional_params' => ['due_date', 'priority', 'description', 'assignee'],
            'extractor' => function ($content, $sources, $metadata) {
                return $this->extractTaskParams($content, $sources, $metadata);
            },
            'executor' => 'task.create',
        ]);

        // View Source Document
        $this->registerAction('view_source', [
            'label' => '📖 View Full Document',
            'description' => 'View the complete source document',
            'context_triggers' => [], // Always available when sources exist
            'required_params' => ['model_class', 'model_id'],
            'optional_params' => [],
            'extractor' => function ($content, $sources, $metadata) {
                return $this->extractSourceParams($sources);
            },
            'executor' => 'source.view',
            'requires_sources' => true,
        ]);

        // Find Similar
        $this->registerAction('find_similar', [
            'label' => '🔍 Find Similar',
            'description' => 'Find similar content in your data',
            'context_triggers' => ['similar', 'related', 'like this'],
            'required_params' => ['model_class', 'model_id'],
            'optional_params' => ['limit'],
            'extractor' => function ($content, $sources, $metadata) {
                $params = $this->extractSourceParams($sources);
                $params['limit'] = 5;
                return $params;
            },
            'executor' => 'source.find_similar',
            'requires_sources' => true,
        ]);

        // Mark Priority
        $this->registerAction('mark_priority', [
            'label' => '⭐ Mark as Priority',
            'description' => 'Mark this item as high priority',
            'context_triggers' => ['urgent', 'important', 'priority', 'asap', 'critical'],
            'required_params' => ['model_class', 'model_id'],
            'optional_params' => ['priority_level'],
            'extractor' => function ($content, $sources, $metadata) {
                $params = $this->extractSourceParams($sources);
                $params['priority_level'] = 'high';
                return $params;
            },
            'executor' => 'item.mark_priority',
            'requires_sources' => true,
        ]);

        // Summarize
        $this->registerAction('summarize', [
            'label' => '📝 Summarize',
            'description' => 'Get a brief summary',
            'context_triggers' => ['long', 'detailed', 'comprehensive'],
            'required_params' => ['content'],
            'optional_params' => ['max_length'],
            'extractor' => function ($content, $sources, $metadata) {
                return [
                    'content' => $content,
                    'max_length' => 200,
                ];
            },
            'executor' => 'ai.summarize',
        ]);

        // Translate
        $this->registerAction('translate', [
            'label' => '🌐 Translate',
            'description' => 'Translate this content',
            'context_triggers' => [], // Manual trigger only
            'required_params' => ['content'],
            'optional_params' => ['target_language'],
            'extractor' => function ($content, $sources, $metadata) {
                return [
                    'content' => $content,
                    'target_language' => 'en', // Default
                ];
            },
            'executor' => 'ai.translate',
        ]);
    }

    /**
     * Register a custom action
     */
    public function registerAction(string $id, array $definition): void
    {
        $this->actionDefinitions[$id] = $definition;
    }

    /**
     * Generate smart actions with pre-filled parameters
     */
    public function generateSmartActions(
        string $content,
        string $sessionId,
        array $sources = [],
        array $metadata = []
    ): array {
        $actions = [];
        $contentLower = strtolower($content);

        foreach ($this->actionDefinitions as $actionId => $definition) {
            // Check if action requires sources
            if (($definition['requires_sources'] ?? false) && empty($sources)) {
                continue;
            }

            // Check context triggers
            $triggered = empty($definition['context_triggers']);
            foreach ($definition['context_triggers'] as $trigger) {
                if (stripos($contentLower, strtolower($trigger)) !== false) {
                    $triggered = true;
                    break;
                }
            }

            if (!$triggered) {
                continue;
            }

            // Extract parameters using the extractor function
            $params = [];
            if (isset($definition['extractor']) && is_callable($definition['extractor'])) {
                try {
                    $params = $definition['extractor']($content, $sources, $metadata);
                } catch (\Exception $e) {
                    Log::warning("Failed to extract params for action {$actionId}: " . $e->getMessage());
                    continue;
                }
            }

            // Check if all required params are filled
            $missingParams = [];
            foreach ($definition['required_params'] as $param) {
                if (!isset($params[$param]) || empty($params[$param])) {
                    $missingParams[] = $param;
                }
            }

            // If missing required params, try AI extraction
            if (!empty($missingParams) && $this->aiService) {
                $aiParams = $this->extractParamsWithAI($content, $missingParams, $actionId, $definition);
                $params = array_merge($params, $aiParams);
                
                // Re-check missing params
                $missingParams = [];
                foreach ($definition['required_params'] as $param) {
                    if (!isset($params[$param]) || empty($params[$param])) {
                        $missingParams[] = $param;
                    }
                }
            }

            // Check if source is from a remote node
            $sourceNode = $this->extractSourceNode($sources, $metadata);
            
            // Create the action
            $actionData = [
                'action' => $actionId,
                'executor' => $definition['executor'],
                'params' => $params,
                'missing_params' => $missingParams,
                'ready' => empty($missingParams),
                'session_id' => $sessionId,
            ];
            
            // Add node info if source is from remote node
            if ($sourceNode) {
                $actionData['node'] = $sourceNode;
                $actionData['remote'] = true;
            }
            
            $action = new InteractiveAction(
                id: $actionId . '_' . uniqid(),
                type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
                label: $definition['label'] . ($sourceNode ? " ({$sourceNode})" : ''),
                description: $definition['description'],
                data: $actionData
            );

            $actions[] = $action;
        }

        // Always add standard actions
        $actions[] = $this->createCopyAction($content);
        $actions[] = $this->createRegenerateAction($sessionId);

        // Add quick replies based on content
        $actions = array_merge($actions, $this->generateQuickReplies($content, $sources));

        return $actions;
    }

    /**
     * Extract parameters using AI
     */
    protected function extractParamsWithAI(string $content, array $missingParams, string $actionId, array $definition): array
    {
        if (!$this->aiService) {
            return [];
        }

        $paramDescriptions = [
            'to_email' => 'email address to send to',
            'subject' => 'email subject line',
            'original_content' => 'the original email content being replied to',
            'draft_body' => 'suggested reply text',
            'title' => 'title or name for the item',
            'date' => 'date in YYYY-MM-DD format',
            'time' => 'time in HH:MM format',
            'duration' => 'duration in minutes',
            'location' => 'location or venue',
            'attendees' => 'list of attendee emails',
            'description' => 'detailed description',
            'due_date' => 'due date in YYYY-MM-DD format',
            'priority' => 'priority level (low, medium, high)',
            'assignee' => 'person assigned to this task',
        ];

        $paramsToExtract = [];
        foreach ($missingParams as $param) {
            $paramsToExtract[$param] = $paramDescriptions[$param] ?? $param;
        }

        $prompt = "Extract the following information from this content. Return ONLY valid JSON.\n\n";
        $prompt .= "Content:\n{$content}\n\n";
        $prompt .= "Extract these fields:\n";
        foreach ($paramsToExtract as $param => $desc) {
            $prompt .= "- {$param}: {$desc}\n";
        }
        $prompt .= "\nReturn JSON like: {\"param1\": \"value1\", \"param2\": \"value2\"}\n";
        $prompt .= "If a value cannot be determined, use null.";

        try {
            $response = $this->aiService->generate(new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                model: 'gpt-4o-mini',
                maxTokens: 500,
            ));

            $responseText = $response->getContent();
            
            // Extract JSON from response
            if (preg_match('/\{[^{}]*\}/', $responseText, $matches)) {
                $extracted = json_decode($matches[0], true);
                if (is_array($extracted)) {
                    // Filter out null values
                    return array_filter($extracted, fn($v) => $v !== null && $v !== '');
                }
            }
        } catch (\Exception $e) {
            Log::warning("AI param extraction failed: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Extract email parameters from content and sources
     */
    protected function extractEmailParams(string $content, array $sources, array $metadata): array
    {
        $params = [];

        // Try to get from first email source
        $emailSource = null;
        foreach ($sources as $source) {
            $type = strtolower($source['model_type'] ?? '');
            if (strpos($type, 'email') !== false || strpos($type, 'mail') !== false) {
                $emailSource = $source;
                break;
            }
        }

        if ($emailSource) {
            // Extract from source metadata
            $params['to_email'] = $emailSource['from_email'] ?? $emailSource['from'] ?? null;
            $params['subject'] = 'Re: ' . ($emailSource['subject'] ?? '');
            $params['original_content'] = $emailSource['content'] ?? $emailSource['body'] ?? substr($content, 0, 500);
            $params['model_id'] = $emailSource['model_id'] ?? $emailSource['id'] ?? null;
            $params['model_class'] = $emailSource['model_class'] ?? null;
        }

        // Extract email from content if not found
        if (empty($params['to_email'])) {
            if (preg_match('/from[:\s]+([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i', $content, $matches)) {
                $params['to_email'] = $matches[1];
            }
        }

        // Extract subject from content
        if (empty($params['subject']) || $params['subject'] === 'Re: ') {
            if (preg_match('/subject[:\s]+["\']?([^"\'\n]+)["\']?/i', $content, $matches)) {
                $params['subject'] = 'Re: ' . trim($matches[1]);
            }
        }

        return $params;
    }

    /**
     * Extract forward parameters
     */
    protected function extractForwardParams(string $content, array $sources, array $metadata): array
    {
        $params = $this->extractEmailParams($content, $sources, $metadata);
        
        if (isset($params['subject'])) {
            $params['original_subject'] = str_replace('Re: ', 'Fwd: ', $params['subject']);
        }
        $params['original_content'] = $params['original_content'] ?? substr($content, 0, 500);
        
        return $params;
    }

    /**
     * Extract calendar event parameters
     */
    protected function extractCalendarParams(string $content, array $sources, array $metadata): array
    {
        $params = [];

        // Extract title - look for meeting/event context
        if (preg_match('/(?:meeting|call|appointment|event)[:\s]+["\']?([^"\'\n,]+)/i', $content, $matches)) {
            $params['title'] = trim($matches[1]);
        } elseif (preg_match('/(?:about|regarding|for)[:\s]+["\']?([^"\'\n,]+)/i', $content, $matches)) {
            $params['title'] = trim($matches[1]);
        }

        // Extract date patterns
        $datePatterns = [
            '/(\d{4}-\d{2}-\d{2})/' => 'Y-m-d',
            '/(\d{1,2}\/\d{1,2}\/\d{2,4})/' => 'm/d/Y',
            '/(\d{1,2}-\d{1,2}-\d{2,4})/' => 'm-d-Y',
            '/(tomorrow)/i' => 'tomorrow',
            '/(next\s+(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday))/i' => 'next_day',
            '/((?:monday|tuesday|wednesday|thursday|friday|saturday|sunday))/i' => 'day',
        ];

        foreach ($datePatterns as $pattern => $format) {
            if (preg_match($pattern, $content, $matches)) {
                if ($format === 'tomorrow') {
                    $params['date'] = date('Y-m-d', strtotime('+1 day'));
                } elseif ($format === 'next_day' || $format === 'day') {
                    $params['date'] = date('Y-m-d', strtotime($matches[1]));
                } else {
                    $params['date'] = $matches[1];
                }
                break;
            }
        }

        // Extract time patterns
        if (preg_match('/(\d{1,2}:\d{2}(?:\s*[AP]M)?)/i', $content, $matches)) {
            $params['time'] = $matches[1];
        } elseif (preg_match('/at\s+(\d{1,2})\s*([AP]M)?/i', $content, $matches)) {
            $hour = (int) $matches[1];
            $ampm = strtoupper($matches[2] ?? 'AM');
            if ($ampm === 'PM' && $hour < 12) $hour += 12;
            $params['time'] = sprintf('%02d:00', $hour);
        }

        // Extract duration
        if (preg_match('/(\d+)\s*(?:hour|hr|h)/i', $content, $matches)) {
            $params['duration'] = (int) $matches[1] * 60;
        } elseif (preg_match('/(\d+)\s*(?:minute|min|m)/i', $content, $matches)) {
            $params['duration'] = (int) $matches[1];
        }

        // Extract location
        if (preg_match('/(?:at|in|location)[:\s]+["\']?([^"\'\n,]+)/i', $content, $matches)) {
            $location = trim($matches[1]);
            if (!preg_match('/^\d{1,2}/', $location)) { // Not a time
                $params['location'] = $location;
            }
        }

        // Extract attendees (emails)
        preg_match_all('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $content, $emailMatches);
        if (!empty($emailMatches[1])) {
            $params['attendees'] = array_unique($emailMatches[1]);
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
        if (preg_match('/(?:task|todo|reminder)[:\s]+["\']?([^"\'\n]+)/i', $content, $matches)) {
            $params['title'] = trim($matches[1]);
        } elseif (preg_match('/(?:need to|should|must|have to)[:\s]+([^.\n]+)/i', $content, $matches)) {
            $params['title'] = trim($matches[1]);
        }

        // Extract due date
        if (preg_match('/(?:due|by|before|deadline)[:\s]+([^.\n,]+)/i', $content, $matches)) {
            $dateStr = trim($matches[1]);
            $timestamp = strtotime($dateStr);
            if ($timestamp) {
                $params['due_date'] = date('Y-m-d', $timestamp);
            }
        }

        // Extract priority
        if (preg_match('/(urgent|critical|high\s*priority|asap)/i', $content)) {
            $params['priority'] = 'high';
        } elseif (preg_match('/(low\s*priority|when\s*possible|eventually)/i', $content)) {
            $params['priority'] = 'low';
        } else {
            $params['priority'] = 'medium';
        }

        return $params;
    }

    /**
     * Extract source parameters
     */
    protected function extractSourceParams(array $sources): array
    {
        if (empty($sources)) {
            return [];
        }

        $source = $sources[0];
        return [
            'model_class' => $source['model_class'] ?? null,
            'model_id' => $source['model_id'] ?? $source['id'] ?? null,
            'model_type' => $source['model_type'] ?? null,
        ];
    }

    /**
     * Create copy action
     */
    protected function createCopyAction(string $content): InteractiveAction
    {
        return new InteractiveAction(
            id: 'copy_' . uniqid(),
            type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
            label: '📋 Copy',
            description: 'Copy response to clipboard',
            data: [
                'action' => 'copy',
                'executor' => 'clipboard.copy',
                'params' => ['content' => $content],
                'ready' => true,
            ]
        );
    }

    /**
     * Create regenerate action
     */
    protected function createRegenerateAction(string $sessionId): InteractiveAction
    {
        return new InteractiveAction(
            id: 'regenerate_' . uniqid(),
            type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
            label: '🔄 Regenerate',
            description: 'Generate a new response',
            data: [
                'action' => 'regenerate',
                'executor' => 'chat.regenerate',
                'params' => ['session_id' => $sessionId],
                'ready' => true,
            ]
        );
    }

    /**
     * Generate quick replies based on content
     */
    protected function generateQuickReplies(string $content, array $sources): array
    {
        $replies = [];
        $contentLower = strtolower($content);

        // If there are numbered options, add quick replies for them
        if (preg_match_all('/^(\d+)\.\s+\*\*([^*]+)\*\*/m', $content, $matches)) {
            foreach (array_slice($matches[1], 0, 3) as $i => $num) {
                $title = trim($matches[2][$i]);
                $replies[] = new InteractiveAction(
                    id: 'quick_option_' . $num . '_' . uniqid(),
                    type: ActionTypeEnum::from(ActionTypeEnum::QUICK_REPLY),
                    label: "#{$num}: " . substr($title, 0, 30) . (strlen($title) > 30 ? '...' : ''),
                    data: [
                        'action' => 'quick_reply',
                        'executor' => 'chat.send',
                        'params' => [
                            'message' => "Tell me more about option {$num}: {$title}",
                        ],
                        'ready' => true,
                    ]
                );
            }
        }

        // Standard quick replies
        if (strpos($contentLower, '?') !== false || strpos($contentLower, 'question') !== false) {
            $replies[] = new InteractiveAction(
                id: 'quick_more_' . uniqid(),
                type: ActionTypeEnum::from(ActionTypeEnum::QUICK_REPLY),
                label: 'Tell me more',
                data: [
                    'action' => 'quick_reply',
                    'executor' => 'chat.send',
                    'params' => ['message' => 'Can you tell me more about that?'],
                    'ready' => true,
                ]
            );
        }

        if (strpos($contentLower, 'email') !== false && !empty($sources)) {
            $replies[] = new InteractiveAction(
                id: 'quick_reply_email_' . uniqid(),
                type: ActionTypeEnum::from(ActionTypeEnum::QUICK_REPLY),
                label: 'Draft a reply',
                data: [
                    'action' => 'quick_reply',
                    'executor' => 'chat.send',
                    'params' => ['message' => 'Draft a professional reply to this email'],
                    'ready' => true,
                ]
            );
        }

        return $replies;
    }

    /**
     * Extract source node from sources or metadata
     */
    protected function extractSourceNode(array $sources, array $metadata): ?string
    {
        // Check metadata for node info
        if (!empty($metadata['source_node'])) {
            return $metadata['source_node'];
        }
        
        if (!empty($metadata['node'])) {
            return $metadata['node'];
        }

        // Check first source for node info
        if (!empty($sources)) {
            $firstSource = $sources[0];
            
            if (!empty($firstSource['node'])) {
                return $firstSource['node'];
            }
            
            if (!empty($firstSource['source_node'])) {
                return $firstSource['source_node'];
            }
            
            // Check if model_class contains node prefix (format: "node_slug:ModelClass")
            if (!empty($firstSource['model_class'])) {
                $modelClass = $firstSource['model_class'];
                if (strpos($modelClass, ':') !== false) {
                    [$nodeSlug, ] = explode(':', $modelClass, 2);
                    return $nodeSlug;
                }
            }
        }

        return null;
    }
}
