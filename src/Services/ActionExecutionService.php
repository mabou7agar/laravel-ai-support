<?php

namespace LaravelAIEngine\Services;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class ActionExecutionService
{
    protected array $handlers = [];
    protected array $executors = [];

    public function __construct(
        protected ?ChatService $chatService = null,
        protected ?AIEngineService $aiService = null,
        protected ?Node\RemoteActionService $remoteActionService = null
    ) {
        $this->registerDefaultHandlers();
        $this->registerExecutors();
    }

    /**
     * Register executor mappings (executor_id => handler)
     */
    protected function registerExecutors(): void
    {
        // Email executors
        $this->executors['email.reply'] = function (array $params, $userId) {
            return $this->executeEmailReply($params, $userId);
        };
        
        $this->executors['email.forward'] = function (array $params, $userId) {
            return $this->executeEmailForward($params, $userId);
        };

        // Calendar executors
        $this->executors['calendar.create'] = function (array $params, $userId) {
            return $this->executeCalendarCreate($params, $userId);
        };

        // Task executors
        $this->executors['task.create'] = function (array $params, $userId) {
            return $this->executeTaskCreate($params, $userId);
        };

        // Source executors
        $this->executors['source.view'] = function (array $params, $userId) {
            return $this->handleViewSource($params, $userId);
        };
        
        $this->executors['source.find_similar'] = function (array $params, $userId) {
            return $this->handleFindSimilar($params, $userId);
        };

        // Item executors
        $this->executors['item.mark_priority'] = function (array $params, $userId) {
            return $this->handleMarkPriority($params, $userId);
        };

        // AI executors
        $this->executors['ai.summarize'] = function (array $params, $userId) {
            return $this->executeAISummarize($params, $userId);
        };
        
        $this->executors['ai.translate'] = function (array $params, $userId) {
            return $this->executeAITranslate($params, $userId);
        };

        // Chat executors
        $this->executors['chat.send'] = function (array $params, $userId, $sessionId) {
            return $this->executeChatSend($params, $userId, $sessionId);
        };
        
        $this->executors['chat.regenerate'] = function (array $params, $userId, $sessionId) {
            return $this->handleRegenerate($params, $userId, $sessionId);
        };

        // Clipboard executors
        $this->executors['clipboard.copy'] = function (array $params, $userId) {
            return $this->handleCopy($params);
        };
    }

    /**
     * Register default action handlers
     */
    protected function registerDefaultHandlers(): void
    {
        // View source document
        $this->registerHandler('view_source', function (array $data, $userId) {
            return $this->handleViewSource($data, $userId);
        });

        // Find similar content
        $this->registerHandler('find_similar', function (array $data, $userId) {
            return $this->handleFindSimilar($data, $userId);
        });

        // Draft reply (for emails)
        $this->registerHandler('draft_reply', function (array $data, $userId, $sessionId) {
            return $this->handleDraftReply($data, $userId, $sessionId);
        });

        // Regenerate response
        $this->registerHandler('regenerate', function (array $data, $userId, $sessionId) {
            return $this->handleRegenerate($data, $userId, $sessionId);
        });

        // Copy content
        $this->registerHandler('copy', function (array $data, $userId) {
            return $this->handleCopy($data);
        });

        // View all sources
        $this->registerHandler('view_all_sources', function (array $data, $userId) {
            return $this->handleViewAllSources($data, $userId);
        });

        // Create calendar event
        $this->registerHandler('create_calendar_event', function (array $data, $userId) {
            return $this->handleCreateCalendarEvent($data, $userId);
        });

        // Mark as priority
        $this->registerHandler('mark_priority', function (array $data, $userId) {
            return $this->handleMarkPriority($data, $userId);
        });

        // Create item
        $this->registerHandler('create_item', function (array $data, $userId) {
            return $this->handleCreateItem($data, $userId);
        });

        // Select option (numbered list)
        $this->registerHandler('select_option', function (array $data, $userId, $sessionId) {
            return $this->handleSelectOption($data, $userId, $sessionId);
        });
    }

    /**
     * Register a custom action handler
     */
    public function registerHandler(string $actionType, callable $handler): void
    {
        $this->handlers[$actionType] = $handler;
    }

    /**
     * Execute an action (supports both legacy handlers and smart executors)
     * 
     * @param string $actionType Action type identifier
     * @param array $data Action data including params, executor, node info
     * @param mixed $userId User ID for access control
     * @param string|null $sessionId Session ID
     * @return array Execution result
     */
    public function execute(string $actionType, array $data, $userId = null, ?string $sessionId = null): array
    {
        // Check if action should be executed on a remote node
        if ($this->shouldExecuteRemotely($data)) {
            return $this->executeOnRemoteNode($actionType, $data, $userId, $sessionId);
        }
        
        // Check if this is a smart action with executor
        if (isset($data['executor']) && isset($this->executors[$data['executor']])) {
            $executor = $this->executors[$data['executor']];
            $params = $data['params'] ?? $data;
            
            // Check if action is ready (all params filled)
            if (isset($data['ready']) && !$data['ready']) {
                $missingParams = $data['missing_params'] ?? [];
                
                // Try to fill missing params with AI
                if (!empty($missingParams) && $this->aiService) {
                    $filledParams = $this->fillMissingParamsWithAI($params, $missingParams, $actionType);
                    $params = array_merge($params, $filledParams);
                    
                    // Re-check if still missing
                    $stillMissing = array_filter($missingParams, fn($p) => empty($params[$p]));
                    if (!empty($stillMissing)) {
                        return [
                            'success' => false,
                            'error' => 'Missing required parameters',
                            'missing_params' => $stillMissing,
                            'action' => 'request_params',
                            'params_needed' => $stillMissing,
                        ];
                    }
                }
            }
            
            return $executor($params, $userId, $sessionId);
        }
        
        // Legacy handler support
        if (isset($this->handlers[$actionType])) {
            $handler = $this->handlers[$actionType];
            return $handler($data, $userId, $sessionId);
        }
        
        throw new \InvalidArgumentException("Unknown action type: {$actionType}");
    }

    /**
     * Check if action should be executed on a remote node
     */
    protected function shouldExecuteRemotely(array $data): bool
    {
        // Check for explicit node specification
        if (!empty($data['node']) || !empty($data['node_slug'])) {
            return true;
        }
        
        // Check if source is from a remote node
        if (!empty($data['params']['source_node'])) {
            return true;
        }
        
        // Check model class for remote indicator (format: "node_slug:ModelClass")
        if (!empty($data['params']['model_class'])) {
            $modelClass = $data['params']['model_class'];
            if (strpos($modelClass, ':') !== false) {
                return true;
            }
            
            // Check if model class belongs to a remote node's collections
            $nodeForCollection = $this->findNodeForCollection($modelClass);
            if ($nodeForCollection !== null) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Find which node owns a specific collection/model class
     * Returns null if collection is local (not owned by any remote node)
     */
    protected function findNodeForCollection(string $modelClass): ?string
    {
        if (!$this->remoteActionService) {
            return null;
        }

        try {
            // Use NodeRegistryService to find the node
            $registry = app(\LaravelAIEngine\Services\Node\NodeRegistryService::class);
            $node = $registry->findNodeForCollection($modelClass);
            
            return $node?->slug;
        } catch (\Exception $e) {
            Log::warning('Failed to find node for collection', [
                'model_class' => $modelClass,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Execute action on a remote node
     */
    protected function executeOnRemoteNode(string $actionType, array $data, $userId, ?string $sessionId): array
    {
        if (!$this->remoteActionService) {
            return [
                'success' => false,
                'error' => 'Remote action service not available',
                'fallback' => 'local',
            ];
        }

        // Determine target node - priority order:
        // 1. Explicit node specification
        // 2. Source node from params
        // 3. Node prefix in model_class (format: "node_slug:ModelClass")
        // 4. Collection-to-node mapping
        $nodeSlug = $data['node'] ?? $data['node_slug'] ?? $data['params']['source_node'] ?? null;
        
        // Extract node from model class if needed (format: "node_slug:ModelClass")
        if (!$nodeSlug && !empty($data['params']['model_class'])) {
            $modelClass = $data['params']['model_class'];
            
            // Check for node prefix format
            if (strpos($modelClass, ':') !== false) {
                [$nodeSlug, $actualModelClass] = explode(':', $modelClass, 2);
                $data['params']['model_class'] = $actualModelClass;
            } else {
                // Find node by collection mapping
                $nodeSlug = $this->findNodeForCollection($modelClass);
            }
        }

        if (!$nodeSlug) {
            return [
                'success' => false,
                'error' => 'No target node specified for remote action',
            ];
        }

        try {
            Log::info('Executing action on remote node', [
                'node' => $nodeSlug,
                'action_type' => $actionType,
                'user_id' => $userId,
            ]);

            // Prepare remote action payload
            $remotePayload = [
                'action_type' => $actionType,
                'executor' => $data['executor'] ?? null,
                'params' => $data['params'] ?? [],
                'user_id' => $userId,
                'session_id' => $sessionId,
            ];

            $result = $this->remoteActionService->executeOn($nodeSlug, $actionType, $remotePayload);

            return [
                'success' => true,
                'executed_on' => $nodeSlug,
                'remote' => true,
                'result' => $result,
            ];

        } catch (\Exception $e) {
            Log::error('Remote action execution failed', [
                'node' => $nodeSlug,
                'action_type' => $actionType,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'node' => $nodeSlug,
                'remote' => true,
            ];
        }
    }

    /**
     * Execute action on multiple nodes
     */
    public function executeOnAllNodes(string $actionType, array $data, $userId = null, ?string $sessionId = null): array
    {
        if (!$this->remoteActionService) {
            return [
                'success' => false,
                'error' => 'Remote action service not available',
            ];
        }

        $payload = [
            'action_type' => $actionType,
            'executor' => $data['executor'] ?? null,
            'params' => $data['params'] ?? [],
            'user_id' => $userId,
            'session_id' => $sessionId,
        ];

        // Execute on all nodes (parallel by default)
        $parallel = $data['parallel'] ?? true;
        $nodeIds = $data['node_ids'] ?? null;

        return $this->remoteActionService->executeOnAll($actionType, $payload, $parallel, $nodeIds);
    }

    /**
     * Get available actions from all nodes
     */
    public function getAvailableActionsFromAllNodes(?string $context = null): array
    {
        $localActions = $this->getAvailableActions($context);
        
        if (!$this->remoteActionService) {
            return [
                'local' => $localActions,
                'remote' => [],
            ];
        }

        try {
            $remoteResult = $this->remoteActionService->executeOnAll('get_available_actions', [
                'context' => $context,
            ]);

            $remoteActions = [];
            if ($remoteResult['success'] ?? false) {
                foreach ($remoteResult['results'] ?? [] as $nodeSlug => $nodeResult) {
                    if ($nodeResult['success'] ?? false) {
                        $remoteActions[$nodeSlug] = [
                            'node' => $nodeSlug,
                            'node_name' => $nodeResult['node_name'] ?? $nodeSlug,
                            'actions' => $nodeResult['data']['actions'] ?? [],
                        ];
                    }
                }
            }

            return [
                'local' => $localActions,
                'remote' => $remoteActions,
            ];

        } catch (\Exception $e) {
            Log::warning('Failed to get remote actions', ['error' => $e->getMessage()]);
            return [
                'local' => $localActions,
                'remote' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fill missing parameters using AI
     */
    protected function fillMissingParamsWithAI(array $currentParams, array $missingParams, string $actionType): array
    {
        if (!$this->aiService) {
            return [];
        }

        $context = $currentParams['content'] ?? $currentParams['original_content'] ?? '';
        if (empty($context)) {
            return [];
        }

        $paramHints = [
            'to_email' => 'recipient email address',
            'subject' => 'email subject line',
            'title' => 'title or name',
            'date' => 'date (YYYY-MM-DD format)',
            'time' => 'time (HH:MM format)',
            'due_date' => 'due date (YYYY-MM-DD format)',
            'priority' => 'priority level (low, medium, high)',
            'description' => 'brief description',
        ];

        $prompt = "Extract the following from this content. Return ONLY JSON.\n\n";
        $prompt .= "Content: " . substr($context, 0, 1000) . "\n\n";
        $prompt .= "Extract:\n";
        foreach ($missingParams as $param) {
            $hint = $paramHints[$param] ?? $param;
            $prompt .= "- {$param}: {$hint}\n";
        }
        $prompt .= "\nReturn: {\"param\": \"value\"} or null if not found.";

        try {
            $response = $this->aiService->generate($this->createAIRequest($prompt, 300));

            if (preg_match('/\{[^{}]*\}/', $response->getContent(), $matches)) {
                $extracted = json_decode($matches[0], true);
                if (is_array($extracted)) {
                    return array_filter($extracted, fn($v) => $v !== null && $v !== '');
                }
            }
        } catch (\Exception $e) {
            Log::warning("AI param fill failed: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Select a numbered option
     */
    public function selectOption(
        int $optionNumber,
        string $sessionId,
        ?int $sourceIndex = null,
        array $sources = [],
        $userId = null
    ): array {
        // If source index provided, get the source details
        $source = null;
        if ($sourceIndex !== null && isset($sources[$sourceIndex])) {
            $source = $sources[$sourceIndex];
        }

        // Get more details about the selected option
        if ($source) {
            return $this->handleViewSource([
                'model_id' => $source['model_id'] ?? $source['id'] ?? null,
                'model_class' => $source['model_class'] ?? null,
            ], $userId);
        }

        // If no source, send as follow-up message
        return [
            'type' => 'follow_up',
            'message' => "Tell me more about option {$optionNumber}",
            'option_number' => $optionNumber,
            'action' => 'send_message',
        ];
    }

    /**
     * Get available actions for a context
     */
    public function getAvailableActions(?string $context = null, $userId = null): array
    {
        $actions = [
            [
                'type' => 'regenerate',
                'label' => '🔄 Regenerate',
                'description' => 'Generate a new response',
                'always_available' => true,
            ],
            [
                'type' => 'copy',
                'label' => '📋 Copy',
                'description' => 'Copy response to clipboard',
                'always_available' => true,
            ],
        ];

        // Context-specific actions
        if ($context === 'email' || $context === null) {
            $actions[] = [
                'type' => 'draft_reply',
                'label' => '✉️ Draft Reply',
                'description' => 'Draft a reply to this email',
                'context' => 'email',
            ];
        }

        if ($context === 'calendar' || $context === null) {
            $actions[] = [
                'type' => 'create_calendar_event',
                'label' => '📅 Add to Calendar',
                'description' => 'Create a calendar event',
                'context' => 'calendar',
            ];
        }

        return $actions;
    }

    // ==================== Action Handlers ====================

    /**
     * Handle view source action
     */
    protected function handleViewSource(array $data, $userId): array
    {
        $modelClass = $data['model_class'] ?? null;
        $modelId = $data['model_id'] ?? null;

        if (!$modelClass || !$modelId) {
            return [
                'success' => false,
                'error' => 'Missing model_class or model_id',
            ];
        }

        if (!class_exists($modelClass)) {
            return [
                'success' => false,
                'error' => "Model class not found: {$modelClass}",
            ];
        }

        try {
            $model = $modelClass::find($modelId);
            
            if (!$model) {
                return [
                    'success' => false,
                    'error' => 'Record not found',
                ];
            }

            // Check access control
            if ($userId !== null) {
                $userIdColumn = $this->getUserIdColumn($model);
                if ($userIdColumn && isset($model->{$userIdColumn})) {
                    if ($model->{$userIdColumn} != $userId && !$this->isAdmin($userId)) {
                        return [
                            'success' => false,
                            'error' => 'Access denied',
                        ];
                    }
                }
            }

            // Return model data
            return [
                'success' => true,
                'type' => 'view_source',
                'data' => [
                    'id' => $model->id,
                    'type' => class_basename($modelClass),
                    'attributes' => $model->toArray(),
                    'created_at' => $model->created_at ?? null,
                    'updated_at' => $model->updated_at ?? null,
                ],
            ];

        } catch (\Exception $e) {
            Log::error('View source failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'Failed to retrieve source',
            ];
        }
    }

    /**
     * Handle find similar action
     */
    protected function handleFindSimilar(array $data, $userId): array
    {
        $modelClass = $data['model_class'] ?? null;
        $modelId = $data['model_id'] ?? null;

        if (!$modelClass || !$modelId) {
            return [
                'success' => false,
                'error' => 'Missing model_class or model_id',
            ];
        }

        try {
            $model = $modelClass::find($modelId);
            
            if (!$model) {
                return [
                    'success' => false,
                    'error' => 'Record not found',
                ];
            }

            // Get vector content for similarity search
            $searchText = '';
            if (method_exists($model, 'getVectorContent')) {
                $searchText = $model->getVectorContent();
            } elseif (isset($model->subject)) {
                $searchText = $model->subject;
            } elseif (isset($model->title)) {
                $searchText = $model->title;
            } elseif (isset($model->name)) {
                $searchText = $model->name;
            }

            if (empty($searchText)) {
                return [
                    'success' => false,
                    'error' => 'Cannot determine search text for similarity',
                ];
            }

            // Perform vector search
            if (method_exists($modelClass, 'vectorSearch')) {
                $similar = $modelClass::vectorSearch($searchText)
                    ->where('id', '!=', $modelId)
                    ->limit(5)
                    ->get();

                return [
                    'success' => true,
                    'type' => 'find_similar',
                    'data' => [
                        'original_id' => $modelId,
                        'similar_items' => $similar->map(function ($item) {
                            return [
                                'id' => $item->id,
                                'title' => $item->subject ?? $item->title ?? $item->name ?? 'Untitled',
                                'score' => $item->vector_score ?? null,
                            ];
                        })->toArray(),
                    ],
                ];
            }

            return [
                'success' => false,
                'error' => 'Vector search not available for this model',
            ];

        } catch (\Exception $e) {
            Log::error('Find similar failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'Failed to find similar items',
            ];
        }
    }

    /**
     * Handle draft reply action
     */
    protected function handleDraftReply(array $data, $userId, ?string $sessionId): array
    {
        $modelId = $data['model_id'] ?? $data['reference_id'] ?? null;
        $modelClass = $data['model_class'] ?? null;

        // Get the original email/message
        $original = null;
        if ($modelClass && $modelId && class_exists($modelClass)) {
            $original = $modelClass::find($modelId);
        }

        // Generate reply draft using AI
        if ($this->chatService && $sessionId) {
            $prompt = "Draft a professional reply to this email";
            if ($original) {
                $subject = $original->subject ?? 'No Subject';
                $from = $original->from_name ?? $original->from_email ?? 'Sender';
                $prompt = "Draft a professional reply to the email from {$from} with subject '{$subject}'";
            }

            try {
                $response = $this->chatService->processMessage(
                    message: $prompt,
                    sessionId: $sessionId,
                    useIntelligentRAG: false
                );

                return [
                    'success' => true,
                    'type' => 'draft_reply',
                    'data' => [
                        'draft' => $response->getContent(),
                        'original_id' => $modelId,
                        'subject' => 'Re: ' . ($original->subject ?? ''),
                        'to' => $original->from_email ?? null,
                    ],
                ];
            } catch (\Exception $e) {
                Log::warning('Draft reply AI generation failed', ['error' => $e->getMessage()]);
            }
        }

        // Fallback: return template
        return [
            'success' => true,
            'type' => 'draft_reply',
            'data' => [
                'draft' => "Hi,\n\nThank you for your email.\n\n[Your reply here]\n\nBest regards",
                'original_id' => $modelId,
                'subject' => 'Re: ' . ($original->subject ?? ''),
                'to' => $original->from_email ?? null,
            ],
        ];
    }

    /**
     * Handle regenerate action
     */
    protected function handleRegenerate(array $data, $userId, ?string $sessionId): array
    {
        if (!$sessionId) {
            return [
                'success' => false,
                'error' => 'Session ID required for regeneration',
            ];
        }

        // Return instruction to resend last message
        return [
            'success' => true,
            'type' => 'regenerate',
            'action' => 'resend_last_message',
            'session_id' => $sessionId,
            'message' => 'Please resend the last message to regenerate the response',
        ];
    }

    /**
     * Handle copy action
     */
    protected function handleCopy(array $data): array
    {
        return [
            'success' => true,
            'type' => 'copy',
            'content' => $data['content'] ?? '',
            'message' => 'Content ready to copy',
        ];
    }

    /**
     * Handle view all sources action
     */
    protected function handleViewAllSources(array $data, $userId): array
    {
        $sources = $data['sources'] ?? [];
        $detailedSources = [];

        foreach ($sources as $source) {
            $modelClass = $source['model_class'] ?? null;
            $modelId = $source['model_id'] ?? $source['id'] ?? null;

            if ($modelClass && $modelId && class_exists($modelClass)) {
                try {
                    $model = $modelClass::find($modelId);
                    if ($model) {
                        $detailedSources[] = [
                            'id' => $model->id,
                            'type' => class_basename($modelClass),
                            'title' => $model->subject ?? $model->title ?? $model->name ?? 'Untitled',
                            'preview' => $this->getPreview($model),
                            'created_at' => $model->created_at ?? null,
                        ];
                    }
                } catch (\Exception $e) {
                    // Skip failed sources
                }
            }
        }

        return [
            'success' => true,
            'type' => 'view_all_sources',
            'data' => [
                'count' => count($detailedSources),
                'sources' => $detailedSources,
            ],
        ];
    }

    /**
     * Handle create calendar event action
     */
    protected function handleCreateCalendarEvent(array $data, $userId): array
    {
        $content = $data['content'] ?? '';
        
        // Extract date/time from content (basic extraction)
        $eventData = [
            'title' => 'New Event',
            'description' => $content,
            'suggested_date' => null,
            'suggested_time' => null,
        ];

        // Try to extract date patterns
        if (preg_match('/(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/', $content, $matches)) {
            $eventData['suggested_date'] = $matches[1];
        }

        // Try to extract time patterns
        if (preg_match('/(\d{1,2}:\d{2}(?:\s*[AP]M)?)/', $content, $matches)) {
            $eventData['suggested_time'] = $matches[1];
        }

        return [
            'success' => true,
            'type' => 'create_calendar_event',
            'action' => 'open_calendar_form',
            'data' => $eventData,
        ];
    }

    /**
     * Handle mark priority action
     */
    protected function handleMarkPriority(array $data, $userId): array
    {
        $modelClass = $data['model_class'] ?? null;
        $modelId = $data['model_id'] ?? null;

        if (!$modelClass || !$modelId) {
            return [
                'success' => false,
                'error' => 'Missing model information',
            ];
        }

        // Check if model has priority field
        if (class_exists($modelClass)) {
            try {
                $model = $modelClass::find($modelId);
                if ($model) {
                    // Try common priority field names
                    foreach (['is_priority', 'priority', 'is_starred', 'starred', 'is_important'] as $field) {
                        if (isset($model->{$field}) || $model->isFillable($field)) {
                            $model->{$field} = true;
                            $model->save();
                            
                            return [
                                'success' => true,
                                'type' => 'mark_priority',
                                'message' => 'Marked as priority',
                                'model_id' => $modelId,
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Mark priority failed', ['error' => $e->getMessage()]);
            }
        }

        return [
            'success' => false,
            'error' => 'Cannot mark as priority - field not available',
        ];
    }

    /**
     * Handle create item action
     */
    protected function handleCreateItem(array $data, $userId): array
    {
        $modelType = $data['model_type'] ?? null;
        $topic = $data['topic'] ?? null;

        return [
            'success' => true,
            'type' => 'create_item',
            'action' => 'open_create_form',
            'data' => [
                'model_type' => $modelType,
                'suggested_topic' => $topic,
                'reference_id' => $data['reference_id'] ?? null,
            ],
        ];
    }

    /**
     * Handle select option action
     */
    protected function handleSelectOption(array $data, $userId, ?string $sessionId): array
    {
        $optionNumber = $data['value'] ?? $data['option_number'] ?? null;
        $sourceIndex = $data['source_index'] ?? null;

        return [
            'success' => true,
            'type' => 'select_option',
            'action' => 'send_follow_up',
            'data' => [
                'message' => "Tell me more about option {$optionNumber}",
                'option_number' => $optionNumber,
                'source_index' => $sourceIndex,
            ],
        ];
    }

    // ==================== Helper Methods ====================

    /**
     * Get user ID column for a model
     */
    protected function getUserIdColumn($model): ?string
    {
        $table = $model->getTable();
        
        foreach (['user_id', 'owner_id', 'created_by', 'author_id'] as $column) {
            if (\Schema::hasColumn($table, $column)) {
                return $column;
            }
        }
        
        return null;
    }

    /**
     * Check if user is admin
     */
    protected function isAdmin($userId): bool
    {
        try {
            $userModel = config('auth.providers.users.model', 'App\\Models\\User');
            $user = $userModel::find($userId);
            
            if (!$user) return false;
            
            if (isset($user->is_admin) && $user->is_admin) return true;
            if (method_exists($user, 'hasRole') && $user->hasRole(['admin', 'super-admin'])) return true;
            
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get preview text from model
     */
    protected function getPreview($model, int $length = 150): string
    {
        $content = $model->body ?? $model->content ?? $model->description ?? $model->text ?? '';
        $content = strip_tags($content);
        
        if (strlen($content) > $length) {
            return substr($content, 0, $length) . '...';
        }
        
        return $content;
    }

    // ==================== Smart Executor Methods ====================

    /**
     * Execute email reply - generates draft with AI
     */
    protected function executeEmailReply(array $params, $userId): array
    {
        $toEmail = $params['to_email'] ?? null;
        $subject = $params['subject'] ?? 'Re: ';
        $originalContent = $params['original_content'] ?? '';

        // Generate reply draft with AI
        $draftBody = $params['draft_body'] ?? null;
        
        if (!$draftBody && $this->aiService && !empty($originalContent)) {
            try {
                $prompt = "Write a professional, concise email reply to this message:\n\n";
                $prompt .= "Original email:\n{$originalContent}\n\n";
                $prompt .= "Write a helpful reply. Be professional but friendly. Do not include subject line or greeting - just the body.";
                
                $response = $this->aiService->generate($this->createAIRequest($prompt, 500));
                
                $draftBody = $response->getContent();
            } catch (\Exception $e) {
                Log::warning("AI draft generation failed: " . $e->getMessage());
                $draftBody = "Thank you for your email. I will review and get back to you shortly.\n\nBest regards";
            }
        }

        return [
            'success' => true,
            'type' => 'email_reply',
            'action' => 'compose_email',
            'data' => [
                'to' => $toEmail,
                'subject' => $subject,
                'body' => $draftBody,
                'original_id' => $params['model_id'] ?? null,
                'ready_to_send' => !empty($toEmail) && !empty($draftBody),
            ],
        ];
    }

    /**
     * Execute email forward
     */
    protected function executeEmailForward(array $params, $userId): array
    {
        return [
            'success' => true,
            'type' => 'email_forward',
            'action' => 'compose_email',
            'data' => [
                'to' => $params['to_email'] ?? null,
                'subject' => $params['original_subject'] ?? 'Fwd: ',
                'body' => "---------- Forwarded message ----------\n\n" . ($params['original_content'] ?? ''),
                'note' => $params['note'] ?? '',
                'ready_to_send' => false, // User needs to add recipient
            ],
        ];
    }

    /**
     * Execute calendar event creation
     */
    protected function executeCalendarCreate(array $params, $userId): array
    {
        $title = $params['title'] ?? 'New Event';
        $date = $params['date'] ?? date('Y-m-d', strtotime('+1 day'));
        $time = $params['time'] ?? '09:00';
        $duration = $params['duration'] ?? 60;
        
        // Generate ICS data for calendar
        $icsData = $this->generateICS($title, $date, $time, $duration, $params);

        return [
            'success' => true,
            'type' => 'calendar_event',
            'action' => 'create_event',
            'data' => [
                'title' => $title,
                'date' => $date,
                'time' => $time,
                'duration_minutes' => $duration,
                'location' => $params['location'] ?? null,
                'attendees' => $params['attendees'] ?? [],
                'description' => $params['description'] ?? '',
                'ics_data' => $icsData,
                'google_calendar_url' => $this->generateGoogleCalendarUrl($title, $date, $time, $duration, $params),
                'ready' => true,
            ],
        ];
    }

    /**
     * Execute task creation
     */
    protected function executeTaskCreate(array $params, $userId): array
    {
        $title = $params['title'] ?? 'New Task';
        
        return [
            'success' => true,
            'type' => 'task',
            'action' => 'create_task',
            'data' => [
                'title' => $title,
                'description' => $params['description'] ?? '',
                'due_date' => $params['due_date'] ?? null,
                'priority' => $params['priority'] ?? 'medium',
                'assignee' => $params['assignee'] ?? null,
                'ready' => true,
            ],
        ];
    }

    /**
     * Execute AI summarize
     */
    protected function executeAISummarize(array $params, $userId): array
    {
        $content = $params['content'] ?? '';
        $maxLength = $params['max_length'] ?? 200;

        if (empty($content)) {
            return ['success' => false, 'error' => 'No content to summarize'];
        }

        if ($this->aiService) {
            try {
                $prompt = "Summarize this in {$maxLength} characters or less:\n\n{$content}";
                $response = $this->aiService->generate($this->createAIRequest($prompt, 200));
                
                return [
                    'success' => true,
                    'type' => 'summary',
                    'data' => [
                        'summary' => $response->getContent(),
                        'original_length' => strlen($content),
                    ],
                ];
            } catch (\Exception $e) {
                return ['success' => false, 'error' => 'Summarization failed'];
            }
        }

        // Fallback: simple truncation
        return [
            'success' => true,
            'type' => 'summary',
            'data' => [
                'summary' => substr($content, 0, $maxLength) . '...',
                'original_length' => strlen($content),
            ],
        ];
    }

    /**
     * Execute AI translate
     */
    protected function executeAITranslate(array $params, $userId): array
    {
        $content = $params['content'] ?? '';
        $targetLanguage = $params['target_language'] ?? 'en';

        if (empty($content)) {
            return ['success' => false, 'error' => 'No content to translate'];
        }

        if ($this->aiService) {
            try {
                $prompt = "Translate this to {$targetLanguage}. Return only the translation:\n\n{$content}";
                $response = $this->aiService->generate($this->createAIRequest($prompt, 1000));
                
                return [
                    'success' => true,
                    'type' => 'translation',
                    'data' => [
                        'translation' => $response->getContent(),
                        'target_language' => $targetLanguage,
                    ],
                ];
            } catch (\Exception $e) {
                return ['success' => false, 'error' => 'Translation failed'];
            }
        }

        return ['success' => false, 'error' => 'AI service not available'];
    }

    /**
     * Execute chat send (for quick replies)
     */
    protected function executeChatSend(array $params, $userId, ?string $sessionId): array
    {
        $message = $params['message'] ?? '';

        if (empty($message)) {
            return ['success' => false, 'error' => 'No message to send'];
        }

        // Return instruction to send message
        return [
            'success' => true,
            'type' => 'chat_send',
            'action' => 'send_message',
            'data' => [
                'message' => $message,
                'session_id' => $sessionId,
            ],
        ];
    }

    // ==================== Helper Methods for Executors ====================

    /**
     * Create an AI request with proper defaults
     */
    protected function createAIRequest(string $prompt, int $maxTokens = 500): AIRequest
    {
        return new AIRequest(
            prompt: $prompt,
            engine: EngineEnum::from('openai'),
            model: EntityEnum::from('gpt-4o-mini'),
            parameters: [],
            userId: null,
            conversationId: null,
            context: [],
            files: [],
            stream: false,
            systemPrompt: null,
            messages: [],
            maxTokens: $maxTokens
        );
    }

    /**
     * Generate ICS calendar data
     */
    protected function generateICS(string $title, string $date, string $time, int $duration, array $params): string
    {
        $startDateTime = $date . 'T' . str_replace(':', '', $time) . '00';
        $endTime = date('Hi', strtotime($time) + ($duration * 60));
        $endDateTime = $date . 'T' . $endTime . '00';
        $uid = uniqid('event-');
        $location = $params['location'] ?? '';
        $description = $params['description'] ?? '';

        return "BEGIN:VCALENDAR\r\n" .
               "VERSION:2.0\r\n" .
               "BEGIN:VEVENT\r\n" .
               "UID:{$uid}\r\n" .
               "DTSTART:{$startDateTime}\r\n" .
               "DTEND:{$endDateTime}\r\n" .
               "SUMMARY:{$title}\r\n" .
               "LOCATION:{$location}\r\n" .
               "DESCRIPTION:{$description}\r\n" .
               "END:VEVENT\r\n" .
               "END:VCALENDAR\r\n";
    }

    /**
     * Generate Google Calendar URL
     */
    protected function generateGoogleCalendarUrl(string $title, string $date, string $time, int $duration, array $params): string
    {
        $startDateTime = str_replace(['-', ':'], '', $date . 'T' . $time . ':00');
        $endTime = date('His', strtotime($time . ':00') + ($duration * 60));
        $endDateTime = str_replace('-', '', $date) . 'T' . $endTime;
        
        $queryParams = [
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => $startDateTime . '/' . $endDateTime,
            'details' => $params['description'] ?? '',
            'location' => $params['location'] ?? '',
        ];

        return 'https://calendar.google.com/calendar/render?' . http_build_query($queryParams);
    }
}
