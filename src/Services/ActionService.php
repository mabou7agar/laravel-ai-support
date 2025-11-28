<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Enums\ActionTypeEnum;

class ActionService
{
    /**
     * Generate suggested actions based on AI response
     */
    public function generateSuggestedActions(string $content, string $sessionId, array $ragMetadata = []): array
    {
        $actions = [];
        
        // RAG-aware actions (if sources are available)
        if (!empty($ragMetadata['sources'])) {
            $actions = array_merge($actions, $this->generateRAGActions($ragMetadata['sources']));
        }

        // Add regenerate action
        $actions[] = new InteractiveAction(
            id: 'regenerate_' . uniqid(),
            type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
            label: '🔄 Regenerate',
            data: ['action' => 'regenerate', 'session_id' => $sessionId]
        );

        // Add copy action
        $actions[] = new InteractiveAction(
            id: 'copy_' . uniqid(),
            type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
            label: '📋 Copy',
            data: ['action' => 'copy', 'content' => $content]
        );

        // Email-specific actions
        if (stripos($content, 'email') !== false || stripos($content, 'inbox') !== false) {
            $actions[] = new InteractiveAction(
                id: 'reply_' . uniqid(),
                type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
                label: '✉️ Draft Reply',
                data: ['action' => 'draft_reply']
            );
        }

        // Calendar actions
        if (stripos($content, 'meeting') !== false || stripos($content, 'calendar') !== false || 
            stripos($content, 'schedule') !== false || preg_match('/\d{1,2}:\d{2}/', $content)) {
            $actions[] = new InteractiveAction(
                id: 'calendar_' . uniqid(),
                type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
                label: '📅 Add to Calendar',
                data: ['action' => 'create_calendar_event', 'content' => $content]
            );
        }

        // Attachment actions
        if (stripos($content, 'attachment') !== false || stripos($content, '.pdf') !== false || 
            stripos($content, '.docx') !== false || stripos($content, 'file') !== false) {
            $actions[] = new InteractiveAction(
                id: 'download_' . uniqid(),
                type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
                label: '📎 View Attachments',
                data: ['action' => 'view_attachments']
            );
        }

        // Priority/urgency actions
        if (stripos($content, 'urgent') !== false || stripos($content, 'important') !== false || 
            stripos($content, 'priority') !== false) {
            $actions[] = new InteractiveAction(
                id: 'prioritize_' . uniqid(),
                type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
                label: '⭐ Mark as Priority',
                data: ['action' => 'mark_priority']
            );
        }

        // Add context-aware quick replies
        if (stripos($content, 'question') !== false || stripos($content, '?') !== false) {
            $actions[] = new InteractiveAction(
                id: 'more_' . uniqid(),
                type: ActionTypeEnum::from(ActionTypeEnum::QUICK_REPLY),
                label: 'Tell me more',
                data: ['reply' => 'Can you tell me more about that?']
            );
        }

        if (stripos($content, 'example') !== false || stripos($content, 'how') !== false) {
            $actions[] = new InteractiveAction(
                id: 'example_' . uniqid(),
                type: ActionTypeEnum::from(ActionTypeEnum::QUICK_REPLY),
                label: 'Show example',
                data: ['reply' => 'Can you show me an example?']
            );
        }

        return $actions;
    }
    
    /**
     * Generate RAG-aware actions based on sources
     */
    protected function generateRAGActions(array $sources): array
    {
        $actions = [];
        
        // Get the most relevant source
        $topSource = $sources[0] ?? null;
        
        if ($topSource) {
            $modelType = $topSource['model_type'] ?? 'Item';
            $modelTypeLower = strtolower($modelType);
            
            // Action: View full source
            $actions[] = new InteractiveAction(
                id: 'view_source_' . uniqid(),
                type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
                label: '📖 View Full ' . $modelType,
                data: [
                    'action' => 'view_source',
                    'model_id' => $topSource['model_id'],
                    'model_class' => $topSource['model_class'],
                    'model_type' => $modelType,
                    'title' => $topSource['title']
                ]
            );
            
            // Action: Create similar item (generic for any model)
            $actions[] = new InteractiveAction(
                id: 'create_' . $modelTypeLower . '_' . uniqid(),
                type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
                label: '✍️ Create ' . $modelType . ' About This',
                data: [
                    'action' => 'create_item',
                    'model_type' => $modelType,
                    'model_class' => $topSource['model_class'],
                    'topic' => $topSource['title'],
                    'reference_id' => $topSource['model_id']
                ]
            );
            
            // Action: Find similar content
            $actions[] = new InteractiveAction(
                id: 'find_similar_' . uniqid(),
                type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
                label: '🔍 Find Similar Content',
                data: [
                    'action' => 'find_similar',
                    'model_id' => $topSource['model_id'],
                    'model_class' => $topSource['model_class']
                ]
            );
        }
        
        // If multiple sources, add "View all sources" action
        if (count($sources) > 1) {
            $actions[] = new InteractiveAction(
                id: 'view_all_sources_' . uniqid(),
                type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
                label: '📚 View All ' . count($sources) . ' Sources',
                data: [
                    'action' => 'view_all_sources',
                    'sources' => $sources
                ]
            );
        }
        
        return $actions;
    }

    /**
     * Execute an action
     */
    public function executeAction(string $actionId, string $actionType, array $data): array
    {
        // Handle different action types
        switch ($actionType) {
            case ActionTypeEnum::BUTTON:
                return $this->handleButtonAction($data);
            
            case ActionTypeEnum::QUICK_REPLY:
                return $this->handleQuickReply($data);
            
            default:
                return [
                    'success' => false,
                    'error' => 'Unknown action type'
                ];
        }
    }

    /**
     * Handle button action
     */
    protected function handleButtonAction(array $data): array
    {
        $action = $data['action'] ?? null;

        switch ($action) {
            case 'regenerate':
                return [
                    'success' => true,
                    'action' => 'regenerate',
                    'message' => 'Regenerating response...'
                ];
            
            case 'copy':
                return [
                    'success' => true,
                    'action' => 'copy',
                    'content' => $data['content'] ?? ''
                ];
            
            case 'create_calendar_event':
                return $this->handleCalendarEvent($data);
            
            case 'draft_reply':
                return [
                    'success' => true,
                    'action' => 'draft_reply',
                    'message' => 'Opening email draft...'
                ];
            
            case 'view_attachments':
                return [
                    'success' => true,
                    'action' => 'view_attachments',
                    'message' => 'Loading attachments...'
                ];
            
            case 'mark_priority':
                return [
                    'success' => true,
                    'action' => 'mark_priority',
                    'message' => 'Email marked as priority'
                ];
            
            // RAG-specific actions
            case 'view_source':
                return $this->handleViewSource($data);
            
            case 'create_item':
            case 'create_post':  // Backward compatibility
                return $this->handleCreateItem($data);
            
            case 'find_similar':
                return $this->handleFindSimilar($data);
            
            case 'view_all_sources':
                return $this->handleViewAllSources($data);
            
            default:
                return [
                    'success' => false,
                    'error' => 'Unknown button action'
                ];
        }
    }
    
    /**
     * Handle calendar event creation
     */
    protected function handleCalendarEvent(array $data): array
    {
        // Extract event details from content
        $content = $data['content'] ?? '';
        
        // Parse for date/time patterns
        $eventData = $this->extractEventDetails($content);
        
        // Generate Google Calendar URL or event JSON
        $calendarEvent = [
            'summary' => $eventData['title'] ?? 'New Event',
            'description' => $eventData['description'] ?? '',
            'start' => [
                'dateTime' => $eventData['start_time'] ?? date('c', strtotime('+1 day')),
                'timeZone' => 'UTC',
            ],
            'end' => [
                'dateTime' => $eventData['end_time'] ?? date('c', strtotime('+1 day +1 hour')),
                'timeZone' => 'UTC',
            ],
        ];
        
        return [
            'success' => true,
            'action' => 'create_calendar_event',
            'event' => $calendarEvent,
            'google_calendar_url' => $this->generateGoogleCalendarUrl($eventData),
            'message' => 'Calendar event created'
        ];
    }
    
    /**
     * Extract event details from text
     */
    protected function extractEventDetails(string $content): array
    {
        $details = [
            'title' => 'Meeting',
            'description' => '',
            'start_time' => null,
            'end_time' => null,
        ];
        
        // Extract time patterns (e.g., "2 PM", "14:00", "2:00 PM")
        if (preg_match('/(\d{1,2}):?(\d{2})?\s*(AM|PM)?/i', $content, $matches)) {
            $hour = (int) $matches[1];
            $minute = isset($matches[2]) ? (int) $matches[2] : 0;
            $meridiem = $matches[3] ?? '';
            
            if (stripos($meridiem, 'PM') !== false && $hour < 12) {
                $hour += 12;
            }
            
            $details['start_time'] = date('c', strtotime("today {$hour}:{$minute}"));
            $details['end_time'] = date('c', strtotime("today {$hour}:{$minute} +1 hour"));
        }
        
        // Extract potential title
        if (preg_match('/meeting|review|call|discussion/i', $content, $matches)) {
            $details['title'] = ucfirst($matches[0]);
        }
        
        $details['description'] = substr($content, 0, 200);
        
        return $details;
    }
    
    /**
     * Generate Google Calendar URL
     */
    protected function generateGoogleCalendarUrl(array $eventData): string
    {
        $params = [
            'action' => 'TEMPLATE',
            'text' => $eventData['title'] ?? 'New Event',
            'details' => $eventData['description'] ?? '',
        ];
        
        if (isset($eventData['start_time'])) {
            $params['dates'] = date('Ymd\THis\Z', strtotime($eventData['start_time'])) . '/' .
                              date('Ymd\THis\Z', strtotime($eventData['end_time'] ?? $eventData['start_time']));
        }
        
        return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
    }

    /**
     * Handle quick reply
     */
    protected function handleQuickReply(array $data): array
    {
        return [
            'success' => true,
            'action' => 'quick_reply',
            'reply' => $data['reply'] ?? ''
        ];
    }
    
    /**
     * Handle view source action
     */
    protected function handleViewSource(array $data): array
    {
        $modelClass = $data['model_class'] ?? null;
        $modelId = $data['model_id'] ?? null;
        
        if (!$modelClass || !$modelId) {
            return [
                'success' => false,
                'error' => 'Missing model information'
            ];
        }
        
        // Generate URL based on model type
        $url = $this->generateModelUrl($modelClass, $modelId);
        
        return [
            'success' => true,
            'action' => 'view_source',
            'url' => $url,
            'model_id' => $modelId,
            'model_class' => $modelClass,
            'title' => $data['title'] ?? 'View Source',
            'message' => 'Opening source...'
        ];
    }
    
    /**
     * Handle create item action (generic for any model)
     */
    protected function handleCreateItem(array $data): array
    {
        $modelType = $data['model_type'] ?? 'Post';
        $modelClass = $data['model_class'] ?? null;
        $topic = $data['topic'] ?? 'New Topic';
        $referenceId = $data['reference_id'] ?? null;
        
        // Extract model name from class or use model type
        $modelName = $modelClass 
            ? strtolower(class_basename($modelClass)) 
            : strtolower($modelType);
        
        // Try to generate route, fallback to generic URL
        $url = null;
        $routeNames = [
            "{$modelName}s.create",  // posts.create
            "{$modelName}.create",   // post.create
            "create.{$modelName}",   // create.post
        ];
        
        foreach ($routeNames as $routeName) {
            try {
                if (\Route::has($routeName)) {
                    $url = route($routeName, ['topic' => $topic, 'reference' => $referenceId]);
                    break;
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        
        // Fallback to generic URL
        if (!$url) {
            $url = url("/{$modelName}s/create?" . http_build_query([
                'topic' => $topic,
                'reference' => $referenceId
            ]));
        }
        
        return [
            'success' => true,
            'action' => 'create_item',
            'model_type' => $modelType,
            'model_class' => $modelClass,
            'url' => $url,
            'topic' => $topic,
            'reference_id' => $referenceId,
            'message' => "Opening {$modelType} editor...",
            'prefill' => [
                'title' => 'About: ' . $topic,
                'content' => "Write your {$modelName} about {$topic}...",
            ]
        ];
    }
    
    /**
     * Handle find similar content action
     */
    protected function handleFindSimilar(array $data): array
    {
        $modelClass = $data['model_class'] ?? null;
        $modelId = $data['model_id'] ?? null;
        
        if (!$modelClass || !$modelId || !class_exists($modelClass)) {
            return [
                'success' => false,
                'error' => 'Invalid model information'
            ];
        }
        
        try {
            // Find the model
            $model = $modelClass::find($modelId);
            
            if (!$model || !method_exists($model, 'similarTo')) {
                return [
                    'success' => false,
                    'error' => 'Model not found or does not support similarity search'
                ];
            }
            
            // Get similar items
            $similar = $model->similarTo(5, 0.5);
            
            return [
                'success' => true,
                'action' => 'find_similar',
                'similar_items' => $similar->map(function($item) {
                    return [
                        'id' => $item->id,
                        'title' => $item->title ?? $item->name ?? 'Item #' . $item->id,
                        'score' => $item->vector_score ?? 0,
                        'url' => $this->generateModelUrl(get_class($item), $item->id)
                    ];
                })->toArray(),
                'count' => $similar->count(),
                'message' => 'Found ' . $similar->count() . ' similar items'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to find similar content: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Handle view all sources action
     */
    protected function handleViewAllSources(array $data): array
    {
        $sources = $data['sources'] ?? [];
        
        return [
            'success' => true,
            'action' => 'view_all_sources',
            'sources' => array_map(function($source) {
                return [
                    'id' => $source['model_id'],
                    'title' => $source['title'],
                    'type' => $source['model_type'],
                    'relevance' => $source['relevance'],
                    'url' => $this->generateModelUrl($source['model_class'], $source['model_id']),
                    'preview' => $source['content_preview'] ?? null
                ];
            }, $sources),
            'count' => count($sources),
            'message' => 'Showing all ' . count($sources) . ' sources'
        ];
    }
    
    /**
     * Generate URL for a model
     */
    protected function generateModelUrl(string $modelClass, int $modelId): string
    {
        // Extract model name from class
        $modelName = strtolower(class_basename($modelClass));
        
        // Try to generate route
        try {
            if (\Route::has("{$modelName}s.show")) {
                return route("{$modelName}s.show", $modelId);
            } elseif (\Route::has("{$modelName}.show")) {
                return route("{$modelName}.show", $modelId);
            }
        } catch (\Exception $e) {
            // Route doesn't exist
        }
        
        // Fallback to generic URL
        return url("/{$modelName}s/{$modelId}");
    }
}
