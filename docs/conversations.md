# Conversations Guide

Complete guide to building conversational AI applications with Laravel AI Engine.

## Table of Contents

- [Introduction](#introduction)
- [Creating Conversations](#creating-conversations)
- [Sending Messages](#sending-messages)
- [Conversation Settings](#conversation-settings)
- [Advanced Features](#advanced-features)
- [Best Practices](#best-practices)

## Introduction

The conversation system enables you to build chat applications with persistent memory, context awareness, and automatic management.

### Features

- 💬 **Persistent Storage** - Conversations stored in database
- 🧠 **Context Awareness** - AI remembers previous messages
- 🎯 **Auto-Titles** - Automatic conversation naming
- 📊 **Analytics** - Track conversation metrics
- 🔄 **Message Trimming** - Automatic history management
- 🎨 **Customizable** - Flexible settings and metadata

## Creating Conversations

### Basic Conversation

```php
use LaravelAIEngine\Services\ConversationManager;

$manager = app(ConversationManager::class);

$conversation = $manager->createConversation(
    userId: auth()->id(),
    title: 'Customer Support Chat'
);
```

### With System Prompt

```php
$conversation = $manager->createConversation(
    userId: auth()->id(),
    title: 'Laravel Expert',
    systemPrompt: 'You are an expert Laravel developer. Provide detailed, technical answers with code examples.'
);
```

### With Settings

```php
$conversation = $manager->createConversation(
    userId: auth()->id(),
    title: 'AI Assistant',
    settings: [
        'max_messages' => 100,
        'temperature' => 0.7,
        'max_tokens' => 1000,
        'auto_title' => true,
        'model' => 'gpt-4o',
    ]
);
```

### With Metadata

```php
$conversation = $manager->createConversation(
    userId: auth()->id(),
    title: 'Support Ticket #123',
    metadata: [
        'ticket_id' => 123,
        'department' => 'support',
        'priority' => 'high',
        'tags' => ['billing', 'refund'],
    ]
);
```

## Sending Messages

### Basic Message

```php
$response = $conversation->sendMessage('How do I create a Laravel middleware?');
echo $response;
```

### Streaming Message

```php
$conversation->streamMessage('Explain Laravel queues', function ($chunk) {
    echo $chunk;
    flush();
});
```

### With Options

```php
$response = $conversation->sendMessage(
    message: 'Write a complex function',
    options: [
        'temperature' => 0.9,
        'max_tokens' => 2000,
    ]
);
```

### Manual Message Management

```php
// Add user message
$userMessage = $manager->addUserMessage(
    conversationId: $conversation->conversation_id,
    content: 'Hello!',
    metadata: ['timestamp' => now()]
);

// Add assistant message
$assistantMessage = $manager->addAssistantMessage(
    conversationId: $conversation->conversation_id,
    content: 'Hi! How can I help you?',
    aiResponse: $response
);
```

## Conversation Settings

### Update Settings

```php
$manager->updateConversationSettings($conversationId, [
    'max_messages' => 200,
    'temperature' => 0.8,
    'model' => 'gpt-4o',
]);
```

### Available Settings

```php
[
    'max_messages' => 100,        // Maximum messages to keep
    'temperature' => 0.7,         // AI creativity (0-1)
    'max_tokens' => 1000,         // Maximum response length
    'auto_title' => true,         // Auto-generate title
    'model' => 'gpt-4o-mini',    // AI model to use
    'top_p' => 1.0,              // Nucleus sampling
    'frequency_penalty' => 0.0,   // Reduce repetition
    'presence_penalty' => 0.0,    // Encourage new topics
]
```

## Advanced Features

### Get Conversation History

```php
$messages = $manager->getConversationMessages($conversationId);

foreach ($messages as $message) {
    echo "{$message->role}: {$message->content}\n";
}
```

### Get Conversation Context

```php
// Get formatted context for AI
$context = $manager->getConversationContext($conversationId);

// Use in custom AI request
$response = AIEngine::chat($newMessage, [
    'messages' => $context,
]);
```

### Conversation Statistics

```php
$stats = $manager->getConversationStats($conversationId);

echo "Total messages: {$stats['total_messages']}\n";
echo "User messages: {$stats['user_messages']}\n";
echo "Assistant messages: {$stats['assistant_messages']}\n";
echo "Created: {$stats['created_at']}\n";
echo "Last activity: {$stats['last_activity']}\n";
```

### Search Conversations

```php
// Get all user conversations
$conversations = $manager->getUserConversations(auth()->id());

// Search by title
$results = $manager->searchConversations(
    userId: auth()->id(),
    query: 'Laravel'
);

// Get recent conversations
$recent = $manager->getRecentConversations(
    userId: auth()->id(),
    limit: 10
);
```

### Clear History

```php
// Clear all messages but keep conversation
$manager->clearConversationHistory($conversationId);

// Delete conversation
$manager->deleteConversation($conversationId);
```

### Auto-Title Generation

```php
// Enable auto-title
$conversation = $manager->createConversation(
    userId: auth()->id(),
    settings: ['auto_title' => true]
);

// First user message generates title
$conversation->sendMessage('What is machine learning?');
// Title becomes: "What is machine learning?"

// Or manually generate title
$manager->generateConversationTitle($conversationId);
```

### Message Trimming

```php
// Set max messages
$conversation = $manager->createConversation(
    userId: auth()->id(),
    settings: ['max_messages' => 50]
);

// When 51st message is added, oldest is removed
// System message is always kept
```

## RAG with Conversations

Combine conversations with vector search for context-aware responses:

```php
$response = $conversation->ragMessage(
    message: 'How do I optimize Laravel queries?',
    modelClass: Documentation::class,
    maxContext: 5
);

echo $response['answer'];

// View sources used
foreach ($response['sources'] as $source) {
    echo "Source: {$source['metadata']['title']}\n";
}
```

## Best Practices

### 1. Use System Prompts

```php
$conversation = $manager->createConversation(
    userId: auth()->id(),
    systemPrompt: 'You are a helpful assistant specialized in Laravel development. 
                   Always provide code examples and explain your reasoning.'
);
```

### 2. Set Appropriate Limits

```php
// For chat applications
$settings = [
    'max_messages' => 100,
    'temperature' => 0.7,
];

// For technical support
$settings = [
    'max_messages' => 200,
    'temperature' => 0.3, // More focused
];

// For creative writing
$settings = [
    'max_messages' => 50,
    'temperature' => 0.9, // More creative
];
```

### 3. Use Metadata

```php
$conversation = $manager->createConversation(
    userId: auth()->id(),
    metadata: [
        'source' => 'web_chat',
        'user_agent' => request()->userAgent(),
        'ip_address' => request()->ip(),
        'session_id' => session()->getId(),
    ]
);
```

### 4. Handle Errors

```php
try {
    $response = $conversation->sendMessage($message);
} catch (\Exception $e) {
    Log::error('Conversation error', [
        'conversation_id' => $conversationId,
        'error' => $e->getMessage(),
    ]);
    
    return response()->json([
        'error' => 'Failed to send message. Please try again.',
    ], 500);
}
```

### 5. Monitor Usage

```php
$stats = $manager->getConversationStats($conversationId);

if ($stats['total_messages'] > 100) {
    // Warn user about long conversation
    // Consider starting new conversation
}
```

## Use Case Examples

### Customer Support Chat

```php
class SupportChatController extends Controller
{
    public function start(Request $request)
    {
        $conversation = app(ConversationManager::class)->createConversation(
            userId: auth()->id(),
            title: 'Support Chat',
            systemPrompt: 'You are a helpful customer support agent. 
                          Be friendly, professional, and solve problems efficiently.',
            metadata: [
                'ticket_id' => $request->ticket_id,
                'department' => 'support',
            ]
        );
        
        return response()->json([
            'conversation_id' => $conversation->conversation_id,
        ]);
    }
    
    public function sendMessage(Request $request)
    {
        $conversation = app(ConversationManager::class)
            ->getConversation($request->conversation_id);
        
        $response = $conversation->sendMessage($request->message);
        
        return response()->json([
            'message' => $response,
        ]);
    }
}
```

### Code Assistant

```php
class CodeAssistantController extends Controller
{
    public function createSession()
    {
        $conversation = app(ConversationManager::class)->createConversation(
            userId: auth()->id(),
            title: 'Code Assistant',
            systemPrompt: 'You are an expert programmer. 
                          Provide clean, well-documented code with explanations.',
            settings: [
                'temperature' => 0.3, // More focused for code
                'max_tokens' => 2000,
            ]
        );
        
        return response()->json(['conversation_id' => $conversation->conversation_id]);
    }
    
    public function askQuestion(Request $request)
    {
        $conversation = app(ConversationManager::class)
            ->getConversation($request->conversation_id);
        
        // Use RAG for code examples
        $response = $conversation->ragMessage(
            message: $request->question,
            modelClass: CodeSnippet::class,
            maxContext: 3
        );
        
        return response()->json($response);
    }
}
```

### Streaming Chat Interface

```php
class StreamingChatController extends Controller
{
    public function stream(Request $request)
    {
        $conversation = app(ConversationManager::class)
            ->getConversation($request->conversation_id);
        
        return response()->stream(function () use ($conversation, $request) {
            $conversation->streamMessage(
                message: $request->message,
                callback: function ($chunk) {
                    echo "data: " . json_encode(['chunk' => $chunk]) . "\n\n";
                    ob_flush();
                    flush();
                }
            );
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
```

## Next Steps

- [RAG Guide](rag.md)
- [Vector Search](vector-search.md)
- [API Reference](api-reference.md)
