# 💬 Data Collector - Conversational Forms

The Data Collector provides a conversational UI for collecting structured data from users with AI-powered field extraction, file analysis, and multilingual support.

## Table of Contents

- [Features](#features)
- [Quick Start](#quick-start)
- [Blade Component](#blade-component)
- [Backend Configuration](#backend-configuration)
- [Field Extraction System](#field-extraction-system)
- [Output Modification](#output-modification)
- [API Endpoints](#api-endpoints)
- [Publishing Routes](#publishing-routes)
- [File Upload](#file-upload)
- [Multilingual Support](#multilingual-support)

---

## Features

### Core Features
- **Conversational Forms**: Replace traditional forms with AI-guided conversations
- **Smart Field Extraction**: 3-layer extraction system (markers → structured text → direct extraction)
- **Robust Field Tracking**: Accurate progress tracking with all fields (required + optional)
- **Field Validation**: Built-in validation with helpful error messages
- **Interactive Actions**: Quick reply buttons and field options
- **Blade Component**: Ready-to-use `<x-ai-engine::data-collector />` component

### AI-Powered Features
- **AI-Generated Summaries**: Dynamic previews with modification support
- **Output Modification**: Modify AI-generated content (lessons, structure) during enhancement
- **Confirmed Output Matching**: Final JSON matches exactly what user confirmed
- **Structured Output**: Generate complex JSON data (courses with lessons, etc.)
- **File Upload & Extraction**: Upload documents to auto-fill fields

### User Experience
- **📊 Accurate Progress Tracking**: Shows all fields (required + optional) in remaining list
- **🎯 Smart Field Extraction**: 3-layer fallback system ensures values are always captured
- **✏️ Output Modification**: Modify AI-generated lessons/content during enhancement phase
- **✅ Confirmed Output Matching**: Final JSON output matches exactly what user confirmed
- **🔄 Direct Extraction Fallback**: Extracts values even when AI doesn't use markers
- **Field Status**: Shows pending, current, completed, and error states
- **Quick Actions**: Auto-generated buttons for select options
- **Confirmation Modal**: Review data before submission with "What will happen" preview
- **Success Modal**: Completion feedback
- **Dark Mode**: Full dark theme support
- **Responsive**: Mobile-friendly design
- **Keyboard Support**: Enter to send, Shift+Enter for new line

### Multilingual Support
- **🌐 Full Arabic/RTL Support**: Translated UI elements and RTL text direction
- **Multi-Language Support**: Force specific language or auto-detect from user input
- **Enhancement Mode**: Users can modify both input fields and generated output

---

## Quick Start

### 1. Register Configuration

```php
use LaravelAIEngine\DTOs\DataCollectorConfig;
use LaravelAIEngine\Facades\DataCollector;

// In a service provider
DataCollector::registerConfig(new DataCollectorConfig(
    name: 'course_creator',
    title: 'Create a New Course',
    fields: [
        'name' => 'Course name | required | min:3 | max:255',
        'description' => 'Course description | required | min:50',
        'level' => [
            'type' => 'select',
            'options' => ['beginner', 'intermediate', 'advanced'],
            'required' => true,
        ],
        'duration' => 'Duration in hours | required | numeric | min:1',
        'lessons_count' => 'Number of lessons | required | numeric | min:1',
    ],
    onComplete: fn($data) => Course::create($data),
    confirmBeforeComplete: true,
    allowEnhancement: true,
));
```

### 2. Use Blade Component

```blade
<x-ai-engine::data-collector 
    :config-name="'course_creator'"
    :title="'Create a New Course'"
    :description="'I will help you create a course step by step.'"
/>
```

---

## Blade Component

### Basic Usage

```blade
{{-- Minimal usage --}}
<x-ai-engine::data-collector 
    :config-name="'course_creator'"
/>

{{-- With customization --}}
<x-ai-engine::data-collector 
    :config-name="'course_creator'"
    :title="'Create a New Course'"
    :description="'I will help you create a course step by step.'"
    :language="'en'"
    :theme="'light'"
    :height="'600px'"
    :show-progress="true"
/>
```

### Arabic/RTL Support

```blade
<x-ai-engine::data-collector 
    :session-id="'user-' . auth()->id() . '-' . time()"
    :title="'إنشاء دورة جديدة'"
    :description="'سأساعدك في إنشاء دورة خطوة بخطوة.'"
    :language="'ar'"
    :config="[
        'name' => 'course_creator',
        'locale' => 'ar',
        'fields' => [
            'name' => [
                'description' => 'اسم الدورة',
                'validation' => 'required|min:3|max:255',
            ],
            'description' => [
                'description' => 'وصف الدورة',
                'validation' => 'required|min:50',
            ],
            'level' => [
                'type' => 'select',
                'description' => 'مستوى الصعوبة',
                'options' => ['beginner', 'intermediate', 'advanced'],
            ],
            'duration' => [
                'description' => 'المدة بالساعات',
                'validation' => 'required|numeric|min:1',
            ],
        ],
        'confirmBeforeComplete' => true,
    ]"
/>
```

### Component Props

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `sessionId` | string | auto | Unique session identifier |
| `configName` | string | '' | Registered config name |
| `title` | string | 'Data Collection' | Header title |
| `description` | string | '' | Header description |
| `language` | string | 'en' | Language: `en` or `ar` for RTL support |
| `theme` | string | 'light' | Theme: `light` or `dark` |
| `height` | string | '500px' | Container height |
| `apiEndpoint` | string | '/api/v1/data-collector' | API endpoint |
| `engine` | string | 'openai' | AI engine |
| `model` | string | 'gpt-4o' | AI model |
| `showProgress` | bool | true | Show progress bar |
| `showFieldList` | bool | true | Show collapsible field list |
| `autoStart` | bool | true | Auto-start session |
| `config` | array | [] | Inline field configuration |

---

## Backend Configuration

### Field Definition Formats

#### Simple String Format
```php
'field_name' => 'Description | required | min:3 | max:255'
```

#### Array Format
```php
'field_name' => [
    'type' => 'text',  // text, select, number, email, etc.
    'description' => 'Field description',
    'validation' => 'required|min:3',
    'required' => true,
    'examples' => ['Example 1', 'Example 2'],
    'options' => ['option1', 'option2'],  // For select fields
]
```

### Advanced Configuration

```php
use LaravelAIEngine\DTOs\DataCollectorConfig;

$config = new DataCollectorConfig(
    name: 'course_creator',
    title: 'Create a New Course',
    locale: 'en',  // or 'ar' for Arabic
    
    // Field definitions
    fields: [
        'name' => 'Course name | required | min:3 | max:255',
        'description' => 'Course description | required | min:50',
        'duration' => 'Duration in hours | required | numeric | min:1',
        'level' => [
            'type' => 'select',
            'options' => ['beginner', 'intermediate', 'advanced'],
            'required' => true,
        ],
        'lessons_count' => 'Number of lessons | required | numeric | min:1 | max:50',
    ],
    
    // AI-generated summary prompt
    summaryPrompt: 'Generate a brief summary of the course based on: {name}, {description}, {level}',
    
    // AI-generated action summary (e.g., lesson structure)
    actionSummaryPrompt: 'Create {lessons_count} lesson titles and descriptions for a {level} course about {name}',
    
    // Structured JSON output schema
    outputSchema: [
        'course' => [
            'name' => 'string',
            'description' => 'string',
            'duration_hours' => 'number',
            'level' => 'string',
        ],
        'lessons' => [
            [
                'order' => 'number',
                'name' => 'string',
                'description' => 'string',
                'duration_minutes' => 'number',
            ]
        ],
    ],
    
    // Completion callback
    onComplete: fn($data) => Course::create($data),
    
    // Options
    confirmBeforeComplete: true,
    allowEnhancement: true,
);

DataCollector::registerConfig($config);
```

---

## Field Extraction System

The Data Collector uses a robust 3-layer extraction system to ensure field values are always captured:

### Layer 1: FIELD_COLLECTED Markers (Primary)

The AI is instructed to use explicit markers:
```
User: "Laravel Course"
AI: "Great! I've noted the course name."
FIELD_COLLECTED:name=Laravel Course
```

### Layer 2: Structured Text Parsing (Fallback)

If markers are missing, parse from formatted summaries:
```
**Course Name**: Laravel Basics
**Duration**: 10 hours
```

### Layer 3: Direct Extraction (Last Resort)

If both fail, extract directly from user message:
```
User: "Laravel Basics"
Current field: name
→ Extracted: name = "Laravel Basics"
```

This ensures **100% field capture rate** even when AI doesn't follow format instructions.

---

## Output Modification

Users can modify AI-generated content (like lessons) during the enhancement phase:

### How It Works

1. **Initial Generation**: AI generates lesson structure based on collected data
2. **User Reviews**: System shows the generated lessons in action summary
3. **User Modifies**: User says "Make lesson 3 more detailed" or "Add a lesson about testing"
4. **System Stores**: Modification requests stored in state metadata
5. **Regeneration**: AI regenerates with modifications applied
6. **Confirmation**: User confirms the updated structure
7. **JSON Generation**: Final JSON uses the confirmed structure

### Example Flow

```
User: "Make the first lesson focus more on Laravel installation"
→ System stores modification
→ Regenerates action summary with change
→ Shows updated lesson structure

User: "Add a lesson about middleware between lesson 4 and 5"
→ System stores second modification
→ Regenerates with both modifications
→ Shows updated structure

User: "Perfect, let's proceed"
→ System confirms
→ Generates JSON matching confirmed structure
```

### Detection Keywords

The system detects output modification requests using keywords:
- lesson, lessons
- structure, curriculum
- content, outline
- syllabus

---

## API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/data-collector/start` | POST | Start a new session with registered config |
| `/api/v1/data-collector/start-custom` | POST | Start with inline config |
| `/api/v1/data-collector/message` | POST | Send a message |
| `/api/v1/data-collector/analyze-file` | POST | Upload and analyze file |
| `/api/v1/data-collector/apply-extracted` | POST | Apply extracted data |
| `/api/v1/data-collector/status/{sessionId}` | GET | Get session status |
| `/api/v1/data-collector/data/{sessionId}` | GET | Get collected data |
| `/api/v1/data-collector/cancel` | POST | Cancel session |

### Example API Usage

```javascript
// Start session
const response = await fetch('/api/v1/data-collector/start', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        config_name: 'course_creator',
        session_id: 'user-123-' + Date.now()
    })
});

// Send message
await fetch('/api/v1/data-collector/message', {
    method: 'POST',
    body: JSON.stringify({
        session_id: sessionId,
        message: 'Laravel Fundamentals'
    })
});

// Get status
const status = await fetch(`/api/v1/data-collector/status/${sessionId}`);
```

---

## Publishing Routes

You can publish and customize the API routes:

```bash
# Publish main API routes (includes Data Collector)
php artisan vendor:publish --tag=ai-engine-routes

# Publish node API routes
php artisan vendor:publish --tag=ai-engine-node-routes
```

### Route Loading Behavior

- **Default**: Routes load automatically from package
- **After Publishing**: Published version takes precedence
- **Fallback**: Delete published file to revert to package routes
- **No Code Changes**: Automatic fallback system

### Published Files

- `routes/ai-engine-api.php` - Main API routes (Data Collector, RAG, etc.)
- `routes/ai-engine-node-api.php` - Node management routes

---

## File Upload

Users can upload documents (PDF, TXT, DOC, DOCX) and the AI will automatically extract relevant data:

```
┌─────────────────────────────────────────────────────────┐
│  📎 Upload File                                          │
│                                                          │
│  User uploads: course_outline.pdf                        │
│                    ↓                                     │
│  AI extracts:                                            │
│  • Name: "Laravel Fundamentals"                          │
│  • Description: "Complete course covering..."            │
│  • Level: "intermediate"                                 │
│  • Duration: 12                                          │
│                    ↓                                     │
│  [✓ Use Data] [✎ Modify] [✕ Discard]                    │
└─────────────────────────────────────────────────────────┘
```

### Extraction Rules

The AI respects field validation rules when extracting:
- **Numeric fields**: Returns numbers only (not "12 hours", just "12")
- **Select fields**: Returns valid options only
- **Required fields**: Attempts to extract all required data
- **Validation**: Extracted values are validated before applying

---

## Multilingual Support

### Arabic/RTL Support

The component fully supports Arabic with:
- RTL text direction
- Translated UI elements (buttons, progress, field labels)
- Arabic AI responses and prompts

| English | Arabic |
|---------|--------|
| 100% complete | 100% مكتمل |
| 4 of 4 fields | 4 من 4 حقول |
| Confirm | تأكيد |
| Modify | تعديل |
| Cancel | إلغاء |
| Use Data | استخدام البيانات |

### Language Configuration

```php
// Backend config
$config = new DataCollectorConfig(
    name: 'course_creator',
    locale: 'ar',  // Forces Arabic
    // ...
);

// Blade component
<x-ai-engine::data-collector 
    :language="'ar'"
    :config-name="'course_creator'"
/>
```

---

## Related Documentation

- [Installation Guide](installation.md)
- [Configuration](configuration.md)
- [Actions System](actions.md)
- [RAG System](rag.md)
- [API Reference](../README.md#api-endpoints)

---

## Support

- **Issues**: [GitHub Issues](https://github.com/mabou7agar/laravel-ai-support/issues)
- **Discussions**: [GitHub Discussions](https://github.com/mabou7agar/laravel-ai-support/discussions)
- **Email**: support@m-tech-stack.com
