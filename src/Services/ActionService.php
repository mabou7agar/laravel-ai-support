<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Enums\ActionTypeEnum;

class ActionService
{
    /**
     * Generate suggested actions based on AI response
     */
    public function generateSuggestedActions(string $content, string $sessionId): array
    {
        $actions = [];

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
}
