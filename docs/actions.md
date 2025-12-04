# Interactive Actions System

The Laravel AI Engine includes a powerful interactive actions system that automatically generates context-aware actions based on AI responses.

## Table of Contents

- [Overview](#overview)
- [Enabling Actions](#enabling-actions)
- [Action Types](#action-types)
- [Numbered Options](#numbered-options)
- [Handling Actions](#handling-actions)
- [Custom Actions](#custom-actions)
- [API Reference](#api-reference)

---

## Overview

The actions system provides:

- **Context-Aware Actions**: AI suggests relevant actions based on response content
- **Numbered Options**: Clickable options extracted from numbered lists in AI responses
- **Unique Identifiers**: Each action/option has a unique ID for reliable selection
- **Source Linking**: Options link back to source documents via `source_index`
- **Custom Actions**: Define your own actions in configuration

---

## Enabling Actions

### API Request

```bash
curl -X POST 'https://your-app.test/ai/chat' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
    "message": "show me my emails",
    "session_id": "user-123",
    "intelligent_rag": true,
    "actions": true
  }'
```

### PHP Code

```php
use LaravelAIEngine\Services\ChatService;

$chat = app(ChatService::class);

$response = $chat->processMessage(
    message: 'show me my emails',
    sessionId: 'user-123',
    useIntelligentRAG: true,
    useActions: true,  // Enable actions
    ragCollections: [Email::class],
    userId: $request->user()->id
);

// Access actions
$actions = $response->getMetadata()['actions'] ?? [];
$numberedOptions = $response->getMetadata()['numbered_options'] ?? [];
```

---

## Action Types

### Available Types

| Type | Constant | Description | Use Case |
|------|----------|-------------|----------|
| `button` | `ActionTypeEnum::BUTTON` | Clickable button | Regenerate, Copy, Reply |
| `quick_reply` | `ActionTypeEnum::QUICK_REPLY` | Pre-defined response | "Tell me more" |
| `link` | `ActionTypeEnum::LINK` | Navigation link | View external URL |
| `form` | `ActionTypeEnum::FORM` | Interactive form | Create new item |
| `menu` | `ActionTypeEnum::MENU` | Dropdown options | Select from list |
| `confirm` | `ActionTypeEnum::CONFIRM` | Confirmation dialog | Delete confirmation |
| `file_upload` | `ActionTypeEnum::FILE_UPLOAD` | File upload | Attach documents |
| `card` | `ActionTypeEnum::CARD` | Rich card | Display content |
| `calendar` | `ActionTypeEnum::CALENDAR` | Date picker | Schedule events |

### Action Structure

```json
{
  "id": "view_source_abc123",
  "type": "button",
  "label": "📖 View Full Email",
  "description": "View the complete email content",
  "data": {
    "action": "view_source",
    "model_id": 382,
    "model_class": "App\\Models\\Email"
  },
  "style": {
    "variant": "primary",
    "size": "medium"
  },
  "disabled": false,
  "loading": false,
  "confirm_message": null,
  "success_message": null,
  "error_message": null,
  "validation": [],
  "metadata": {}
}
```

### Auto-Generated Actions

The system automatically generates these actions based on context:

| Action | Trigger | Data |
|--------|---------|------|
| `view_source` | RAG sources present | `model_id`, `model_class` |
| `create_item` | Content type detected | `model_type`, `topic` |
| `find_similar` | Search results | `model_id`, `model_class` |
| `view_all_sources` | Multiple sources | `sources` array |
| `regenerate` | Always | `session_id` |
| `copy` | Always | `content` |
| `draft_reply` | Email content | - |
| `create_calendar_event` | Meeting/schedule | `content` |
| `view_attachments` | Attachments mentioned | - |
| `mark_priority` | Urgent/important | - |

---

## Numbered Options

When the AI returns a numbered list, options are automatically extracted:

### Response Example

```json
{
  "response": "Here are your emails:\n\n1. **Undelivered Mail**: Message could not be delivered [Source 0]\n\n2. **Meeting Reminder**: AWS sync meeting tomorrow [Source 1]",
  "numbered_options": [
    {
      "id": "opt_1_abc123",
      "number": 1,
      "text": "Undelivered Mail",
      "full_text": "Undelivered Mail: Message could not be delivered",
      "preview": "Undelivered Mail",
      "source_index": 0,
      "clickable": true,
      "action": "select_option",
      "value": "1"
    },
    {
      "id": "opt_2_def456",
      "number": 2,
      "text": "Meeting Reminder",
      "full_text": "Meeting Reminder: AWS sync meeting tomorrow",
      "preview": "Meeting Reminder",
      "source_index": 1,
      "clickable": true,
      "action": "select_option",
      "value": "2"
    }
  ],
  "has_options": true
}
```

### Option Fields

| Field | Type | Description |
|-------|------|-------------|
| `id` | string | Unique identifier (format: `opt_{number}_{hash}`) |
| `number` | int | Display number (1, 2, 3...) |
| `text` | string | Extracted title/subject |
| `full_text` | string | Full description (up to 200 chars) |
| `preview` | string | Short preview (up to 100 chars) |
| `source_index` | int\|null | Index in `sources` array |
| `clickable` | bool | Whether option is clickable |
| `action` | string | Action type (`select_option`) |
| `value` | string | Value to send when selected |

### Linking to Sources

```javascript
// Get the source document for an option
const option = response.numbered_options[0];
if (option.source_index !== null) {
    const source = response.sources[option.source_index];
    console.log('Source:', source.model_class, source.model_id);
}
```

---

## Handling Actions

### Frontend JavaScript

```javascript
function handleAction(action) {
    switch (action.data.action) {
        case 'regenerate':
            // Re-send the last message
            sendMessage(lastMessage, action.data.session_id);
            break;
            
        case 'copy':
            // Copy content to clipboard
            navigator.clipboard.writeText(action.data.content);
            showToast('Copied to clipboard!');
            break;
            
        case 'draft_reply':
            // Open reply composer
            openReplyComposer();
            break;
            
        case 'view_source':
            // Fetch and display source document
            fetchSource(action.data.model_class, action.data.model_id);
            break;
            
        case 'find_similar':
            // Search for similar content
            searchSimilar(action.data.model_class, action.data.model_id);
            break;
            
        case 'view_all_sources':
            // Display all sources in a modal
            displaySources(action.data.sources);
            break;
            
        case 'select_option':
            // User selected a numbered option
            sendMessage(action.value, sessionId);
            break;
            
        case 'create_calendar_event':
            // Create calendar event
            createCalendarEvent(action.data.content);
            break;
    }
}

// Handle numbered option click
function handleOptionClick(option) {
    // Option 1: Send the number as a follow-up
    sendMessage(option.value, sessionId);
    
    // Option 2: Use the source directly
    if (option.source_index !== null) {
        const source = currentResponse.sources[option.source_index];
        viewDocument(source.model_class, source.model_id);
    }
}
```

### React Example

```jsx
function ActionButton({ action, onAction }) {
    return (
        <button
            onClick={() => onAction(action)}
            disabled={action.disabled}
            className={`action-btn action-${action.type}`}
        >
            {action.loading ? <Spinner /> : action.label}
        </button>
    );
}

function NumberedOptions({ options, onSelect }) {
    return (
        <div className="numbered-options">
            {options.map(option => (
                <button
                    key={option.id}
                    onClick={() => onSelect(option)}
                    className="option-btn"
                >
                    <span className="option-number">{option.number}</span>
                    <span className="option-text">{option.text}</span>
                </button>
            ))}
        </div>
    );
}
```

---

## Custom Actions

### Configuration

Create `config/ai-actions.php`:

```php
<?php

return [
    'rag' => [
        'enabled' => true,
    ],
    
    'actions' => [
        [
            'id' => 'send_email',
            'type' => 'api_call',
            'label' => '📧 Send Email',
            'description' => 'Send an email to a recipient',
            'endpoint' => '/api/emails/send',
            'method' => 'POST',
            'required_fields' => ['to', 'subject', 'body'],
            'example_payload' => [
                'to' => 'user@example.com',
                'subject' => 'Hello',
                'body' => 'Email content here'
            ]
        ],
        [
            'id' => 'create_task',
            'type' => 'api_call',
            'label' => '✅ Create Task',
            'description' => 'Create a new task',
            'endpoint' => '/api/tasks',
            'method' => 'POST',
            'required_fields' => ['title'],
            'example_payload' => [
                'title' => 'New Task',
                'description' => 'Task description'
            ]
        ],
        [
            'id' => 'schedule_meeting',
            'type' => 'api_call',
            'label' => '📅 Schedule Meeting',
            'description' => 'Schedule a new meeting',
            'endpoint' => '/api/meetings',
            'method' => 'POST',
            'required_fields' => ['title', 'date', 'attendees'],
        ]
    ]
];
```

### Using DynamicActionService

```php
use LaravelAIEngine\Services\DynamicActionService;

$actionService = app(DynamicActionService::class);

// Discover all available actions
$actions = $actionService->discoverActions();

// Get recommended actions based on query
$recommended = $actionService->getRecommendedActions(
    query: 'create a new email',
    conversationId: 'conv-123'
);

// Execute an action
$result = $actionService->executeAction($action, [
    'to' => 'user@example.com',
    'subject' => 'Hello',
    'body' => 'Content'
]);
```

### Creating Actions Programmatically

```php
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Enums\ActionTypeEnum;

// Button action
$button = InteractiveAction::button(
    id: 'my_action_1',
    label: '🚀 Do Something',
    data: ['action' => 'custom_action', 'param' => 'value']
);

// Quick reply
$quickReply = InteractiveAction::quickReply(
    id: 'quick_1',
    label: 'Tell me more',
    message: 'Can you tell me more about that?'
);

// Link
$link = InteractiveAction::link(
    id: 'link_1',
    label: 'View Details',
    url: '/details/123',
    external: false
);

// Form
$form = InteractiveAction::form(
    id: 'form_1',
    label: 'Submit Feedback',
    fields: [
        ['name' => 'rating', 'type' => 'number', 'label' => 'Rating (1-5)'],
        ['name' => 'comment', 'type' => 'textarea', 'label' => 'Comment']
    ]
);

// Menu/Dropdown
$menu = InteractiveAction::menu(
    id: 'menu_1',
    label: 'Select Priority',
    options: [
        ['value' => 'high', 'label' => 'High'],
        ['value' => 'medium', 'label' => 'Medium'],
        ['value' => 'low', 'label' => 'Low']
    ]
);

// With confirmation
$deleteAction = InteractiveAction::button(
    id: 'delete_1',
    label: '🗑️ Delete',
    data: ['action' => 'delete', 'id' => 123]
)->withConfirmation('Are you sure you want to delete this?');
```

---

## Action Execution API

The package provides dedicated endpoints for executing actions:

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/v1/actions/execute` | Execute any action |
| `POST` | `/api/v1/actions/select-option` | Select a numbered option |
| `GET` | `/api/v1/actions/available` | Get available actions |

### Execute Action

```bash
POST /api/v1/actions/execute
Content-Type: application/json
Authorization: Bearer YOUR_TOKEN

{
  "action_type": "view_source",
  "data": {
    "model_class": "App\\Models\\Email",
    "model_id": 382
  },
  "session_id": "user-123"
}
```

**Response:**
```json
{
  "success": true,
  "action_type": "view_source",
  "result": {
    "success": true,
    "type": "view_source",
    "data": {
      "id": 382,
      "type": "Email",
      "attributes": { ... }
    }
  }
}
```

### Select Option

```bash
POST /api/v1/actions/select-option
Content-Type: application/json
Authorization: Bearer YOUR_TOKEN

{
  "option_number": 1,
  "session_id": "user-123",
  "source_index": 0,
  "sources": [
    {"model_id": 382, "model_class": "App\\Models\\Email"}
  ]
}
```

**Response:**
```json
{
  "success": true,
  "result": {
    "success": true,
    "type": "view_source",
    "data": { ... }
  }
}
```

### Get Available Actions

```bash
GET /api/v1/actions/available?context=email
Authorization: Bearer YOUR_TOKEN
```

**Response:**
```json
{
  "success": true,
  "actions": [
    {
      "type": "regenerate",
      "label": "🔄 Regenerate",
      "description": "Generate a new response",
      "always_available": true
    },
    {
      "type": "draft_reply",
      "label": "✉️ Draft Reply",
      "description": "Draft a reply to this email",
      "context": "email"
    }
  ]
}
```

### Supported Action Types

| Action Type | Description | Required Data |
|-------------|-------------|---------------|
| `view_source` | View source document | `model_class`, `model_id` |
| `find_similar` | Find similar content | `model_class`, `model_id` |
| `draft_reply` | Draft email reply | `model_id` (optional) |
| `regenerate` | Regenerate response | `session_id` |
| `copy` | Copy content | `content` |
| `view_all_sources` | View all sources | `sources` array |
| `create_calendar_event` | Create calendar event | `content` |
| `mark_priority` | Mark as priority | `model_class`, `model_id` |
| `create_item` | Create new item | `model_type` |
| `select_option` | Select numbered option | `value`, `source_index` |

---

## API Reference

### InteractiveAction Class

```php
class InteractiveAction
{
    // Static constructors
    public static function button(string $id, string $label, array $data = []): self;
    public static function quickReply(string $id, string $label, string $message): self;
    public static function link(string $id, string $label, string $url, bool $external = false): self;
    public static function form(string $id, string $label, array $fields): self;
    public static function menu(string $id, string $label, array $options): self;
    public static function confirm(string $id, string $label, string $confirmMessage): self;
    public static function fileUpload(string $id, string $label, array $allowedTypes = []): self;
    
    // Modifiers
    public function disabled(bool $disabled = true): self;
    public function loading(bool $loading = true): self;
    public function withConfirmation(string $message): self;
    
    // Getters
    public function getId(): string;
    public function getType(): ActionTypeEnum;
    public function getLabel(): string;
    public function getData(): array;
    
    // Serialization
    public function toArray(): array;
    public static function fromArray(array $data): self;
}
```

### ActionTypeEnum

```php
class ActionTypeEnum
{
    public const BUTTON = 'button';
    public const LINK = 'link';
    public const FORM = 'form';
    public const QUICK_REPLY = 'quick_reply';
    public const FILE_UPLOAD = 'file_upload';
    public const CONFIRM = 'confirm';
    public const MENU = 'menu';
    public const CARD = 'card';
    public const CAROUSEL = 'carousel';
    public const LIST = 'list';
    public const CALENDAR = 'calendar';
    public const LOCATION = 'location';
    public const PAYMENT = 'payment';
    public const SHARE = 'share';
    public const DOWNLOAD = 'download';
    public const COPY = 'copy';
    public const VOTE = 'vote';
    public const RATING = 'rating';
    public const SURVEY = 'survey';
    public const CUSTOM = 'custom';
    
    public function description(): string;
    public function requiredFields(): array;
    public function optionalFields(): array;
    public function defaultStyle(): array;
}
```

---

## Best Practices

1. **Always check `has_options`** before rendering numbered options
2. **Use unique IDs** for action handling, not just numbers
3. **Link options to sources** using `source_index` for context
4. **Handle loading states** for async actions
5. **Provide feedback** after action execution
6. **Cache action configurations** for performance

---

## Related Documentation

- [RAG Guide](rag.md) - Retrieval-Augmented Generation
- [Conversations](conversations.md) - Chat with memory
- [Configuration](configuration.md) - All configuration options
