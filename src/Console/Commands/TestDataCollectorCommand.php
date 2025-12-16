<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\DTOs\DataCollectorConfig;
use LaravelAIEngine\Services\DataCollector\DataCollectorService;
use LaravelAIEngine\Services\DataCollector\DataCollectorChatService;

class TestDataCollectorCommand extends Command
{
    protected $signature = 'ai:test-data-collector
                            {--session= : Session ID for the collection}
                            {--preset= : Use a preset config (course, feedback, support)}
                            {--engine=openai : AI engine to use}
                            {--model=gpt-4o : AI model to use}
                            {--locale= : Force AI responses in specific language (e.g., ar, fr, es)}
                            {--detect-locale : Auto-detect language from user input}';

    protected $description = 'Test the Data Collector Chat feature interactively';

    public function handle()
    {
        $this->info('🎯 Data Collector Chat Test');
        $this->newLine();

        $sessionId = $this->option('session') ?? 'dc-test-' . uniqid();
        $preset = $this->option('preset') ?? $this->choice(
            'Select a preset configuration',
            ['course', 'feedback', 'support', 'custom'],
            0
        );
        $engine = $this->option('engine');
        $model = $this->option('model');
        $locale = $this->option('locale');
        $detectLocale = $this->option('detect-locale');

        $this->info("Session ID: {$sessionId}");
        $this->info("Engine: {$engine}");
        $this->info("Model: {$model}");
        if ($locale) {
            $this->info("Locale: {$locale}");
        } elseif ($detectLocale) {
            $this->info("Locale: Auto-detect from user input");
        }
        $this->newLine();

        // Get config based on preset
        $config = $this->getPresetConfig($preset, $locale, $detectLocale);
        
        if (!$config) {
            $this->error('Failed to create configuration');
            return 1;
        }

        $this->info("📋 Configuration: {$config->title}");
        $this->info("Fields to collect: " . implode(', ', $config->getFieldNames()));
        $this->newLine();

        // Get the service
        /** @var DataCollectorChatService $chatService */
        $chatService = app(DataCollectorChatService::class);

        // Start the collection
        $this->info('🚀 Starting data collection...');
        $this->newLine();

        $response = $chatService->startCollection($sessionId, $config);
        
        $this->displayResponse($response);

        // Interactive loop
        while (true) {
            $this->newLine();
            $message = $this->ask('Your response (or "quit" to exit)');

            if (strtolower($message) === 'quit' || strtolower($message) === 'exit') {
                $this->info('👋 Exiting...');
                break;
            }

            if (empty($message)) {
                continue;
            }

            $this->newLine();
            $this->info('⏳ Processing...');

            $response = $chatService->processMessage($sessionId, $message, $engine, $model);
            
            $this->displayResponse($response);

            // Check if complete or cancelled
            $metadata = $response->getMetadata();
            if ($metadata['is_complete'] ?? false) {
                $this->newLine();
                $this->info('✅ Data collection completed!');
                
                // Show collected data
                $this->newLine();
                $this->info('📊 Collected Data:');
                $data = $chatService->getCollectedData($sessionId);
                foreach ($data as $key => $value) {
                    if ($key === '_generated_output') continue;
                    $this->line("  • {$key}: {$value}");
                }
                
                // Show generated output if available
                $generatedOutput = $metadata['generated_output'] ?? null;
                if ($generatedOutput) {
                    $this->newLine();
                    $this->info('🤖 AI-Generated Structured Output:');
                    $this->line(json_encode($generatedOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
                break;
            }

            if ($metadata['is_cancelled'] ?? false) {
                $this->newLine();
                $this->warn('❌ Data collection cancelled.');
                break;
            }
        }

        return 0;
    }

    protected function displayResponse($response): void
    {
        $content = $response->getContent();
        $metadata = $response->getMetadata();
        $actions = $response->getActions();

        // Display the AI message
        $this->newLine();
        $this->line('─────────────────────────────────────────────────────────');
        $this->info('🤖 Assistant:');
        $this->newLine();
        
        // Format and display content
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            if (str_starts_with($line, '##')) {
                $this->info($line);
            } elseif (str_starts_with($line, '**')) {
                $this->line("<fg=yellow>{$line}</>");
            } elseif (str_starts_with($line, '- ')) {
                $this->line("  {$line}");
            } else {
                $this->line($line);
            }
        }

        $this->line('─────────────────────────────────────────────────────────');

        // Display status info
        if (!empty($metadata)) {
            $status = $metadata['status'] ?? 'unknown';
            $progress = $metadata['progress'] ?? 0;
            
            $this->newLine();
            $this->line("<fg=gray>Status: {$status} | Progress: {$progress}%</>");
            
            if (!empty($metadata['collected_fields'])) {
                $this->line("<fg=green>✓ Collected: " . implode(', ', $metadata['collected_fields']) . "</>");
            }
            
            if (!empty($metadata['remaining_fields'])) {
                $this->line("<fg=yellow>○ Remaining: " . implode(', ', $metadata['remaining_fields']) . "</>");
            }
        }

        // Display available actions
        if (!empty($actions)) {
            $this->newLine();
            $this->line('<fg=cyan>Quick actions:</>');
            foreach ($actions as $action) {
                $label = is_array($action) ? ($action['label'] ?? 'Action') : $action->label;
                $this->line("  [{$label}]");
            }
        }
    }

    protected function getPresetConfig(string $preset, ?string $locale = null, bool $detectLocale = false): ?DataCollectorConfig
    {
        return match ($preset) {
            'course' => $this->getCourseConfig($locale, $detectLocale),
            'feedback' => $this->getFeedbackConfig($locale, $detectLocale),
            'support' => $this->getSupportConfig($locale, $detectLocale),
            'custom' => $this->getCustomConfig($locale, $detectLocale),
            default => $this->getCourseConfig($locale, $detectLocale),
        };
    }

    protected function getCourseConfig(?string $locale = null, bool $detectLocale = false): DataCollectorConfig
    {
        return new DataCollectorConfig(
            name: 'course_creator_test',
            title: 'Create a New Course',
            description: 'I will help you create a new course by collecting the necessary information.',
            fields: [
                'name' => 'The course name | required | min:3 | max:255',
                'description' => 'A brief description of what students will learn | required | min:20',
                'duration' => 'Course duration in hours | required | numeric | min:1',
                'level' => [
                    'type' => 'select',
                    'description' => 'The difficulty level',
                    'options' => ['beginner', 'intermediate', 'advanced'],
                    'required' => true
                ],
                'lessons_count' => 'Number of lessons to create | required | numeric | min:1 | max:20',
            ],
            actionSummaryPrompt: <<<PROMPT
Based on this course information, generate a preview of the course structure.

Create exactly {lessons_count} lessons for this "{name}" course. For each lesson, provide:
1. **Lesson Title** - A clear, engaging title
2. **Description** - 1-2 sentences describing what will be covered
3. **Duration** - Estimated time in minutes

Make sure the lessons:
- Progress logically from basics to more advanced concepts
- Cover the main topics mentioned in the course description
- Are appropriate for the {level} level
- Total duration roughly matches {duration} hours

Format as a numbered list with clear markdown formatting.
PROMPT,
            actionSummaryPromptConfig: [
                'engine' => 'openai',
                'model' => 'gpt-4o',
                'max_tokens' => 2000,
            ],
            // Define the structured output schema
            outputSchema: [
                'course' => [
                    'name' => 'string // Course name',
                    'description' => 'string // Course description',
                    'duration_hours' => 'number // Total duration in hours',
                    'level' => 'string // beginner, intermediate, or advanced',
                ],
                'lessons' => [
                    'type' => 'array',
                    'description' => 'List of lessons for the course',
                    'count' => '{lessons_count}', // Dynamic count from collected data
                    'items' => [
                        'order' => 'number // Lesson order (1, 2, 3...)',
                        'name' => 'string // Lesson title',
                        'description' => 'string // What students will learn',
                        'duration_minutes' => 'number // Estimated duration in minutes',
                        'objectives' => [
                            'type' => 'array',
                            'description' => 'Learning objectives',
                            'count' => 3,
                            'items' => [
                                'objective' => 'string // A specific learning objective',
                            ],
                        ],
                    ],
                ],
            ],
            outputPrompt: 'Generate a complete course structure with {lessons_count} lessons based on the course "{name}". Each lesson should have clear objectives and appropriate duration. The total duration should roughly match {duration} hours.',
            outputConfig: [
                'engine' => 'openai',
                'model' => 'gpt-4o',
                'max_tokens' => 4000,
            ],
            confirmBeforeComplete: true,
            allowEnhancement: true,
            onComplete: function (array $data) {
                // In a real app, this would create the course
                // The generated output is available in $data['_generated_output']
                return [
                    'success' => true,
                    'message' => 'Course would be created with: ' . json_encode($data),
                ];
            },
            successMessage: '🎉 Course configuration complete! In a real application, the course would now be created.',
            locale: $locale,
            detectLocale: $detectLocale,
        );
    }

    protected function getFeedbackConfig(?string $locale = null, bool $detectLocale = false): DataCollectorConfig
    {
        return new DataCollectorConfig(
            name: 'feedback_test',
            title: 'Customer Feedback',
            description: 'Please share your feedback to help us improve.',
            fields: [
                'rating' => 'Your overall rating from 1-5 | required | numeric | min:1 | max:5',
                'liked' => 'What did you like most about our service?',
                'improvements' => 'What could we improve?',
                'recommend' => [
                    'type' => 'select',
                    'description' => 'Would you recommend us to others?',
                    'options' => ['definitely', 'probably', 'not sure', 'probably not', 'definitely not'],
                    'required' => true
                ],
            ],
            actionSummary: 'Your feedback will be recorded and reviewed by our team. We use this information to continuously improve our services. Thank you for taking the time to share your thoughts!',
            confirmBeforeComplete: true,
            allowEnhancement: true,
            onComplete: fn($data) => ['success' => true, 'feedback' => $data],
            successMessage: '🙏 Thank you for your feedback!',
            locale: $locale,
            detectLocale: $detectLocale,
        );
    }

    protected function getSupportConfig(?string $locale = null, bool $detectLocale = false): DataCollectorConfig
    {
        return new DataCollectorConfig(
            name: 'support_ticket_test',
            title: 'Create Support Ticket',
            description: 'I will help you create a support ticket.',
            fields: [
                'subject' => 'Brief subject line | required | max:100',
                'description' => 'Detailed description of your issue | required | min:20',
                'priority' => [
                    'type' => 'select',
                    'description' => 'Priority level',
                    'options' => ['low', 'medium', 'high', 'urgent'],
                    'default' => 'medium',
                    'required' => true
                ],
                'category' => [
                    'type' => 'select',
                    'description' => 'Issue category',
                    'options' => ['billing', 'technical', 'account', 'general'],
                    'required' => true
                ],
            ],
            actionSummaryGenerator: function (array $data) {
                $priority = $data['priority'] ?? 'medium';
                $category = $data['category'] ?? 'general';
                
                $response = "A support ticket will be created with the following actions:\n\n";
                $response .= "1. **Ticket Created** - A new {$priority} priority ticket in the {$category} category\n";
                $response .= "2. **Team Notification** - The appropriate support team will be notified\n";
                
                if ($priority === 'urgent' || $priority === 'high') {
                    $response .= "3. **Escalation** - Due to {$priority} priority, this will be escalated immediately\n";
                }
                
                $response .= "4. **Confirmation Email** - You will receive a confirmation with your ticket number\n";
                $response .= "\nExpected response time: " . match($priority) {
                    'urgent' => '1-2 hours',
                    'high' => '4-8 hours',
                    'medium' => '24-48 hours',
                    'low' => '3-5 business days',
                    default => '24-48 hours'
                };
                
                return $response;
            },
            confirmBeforeComplete: true,
            allowEnhancement: true,
            onComplete: fn($data) => ['success' => true, 'ticket_id' => 'TKT-' . strtoupper(uniqid())],
            successMessage: '🎫 Support ticket created successfully!',
            locale: $locale,
            detectLocale: $detectLocale,
        );
    }

    protected function getCustomConfig(?string $locale = null, bool $detectLocale = false): DataCollectorConfig
    {
        $this->info('Creating custom configuration...');
        $this->newLine();

        $name = $this->ask('Config name', 'custom_test');
        $title = $this->ask('Title', 'Custom Data Collection');
        $description = $this->ask('Description', 'Collecting custom data');

        // Collect fields
        $fields = [];
        $this->info('Add fields (enter empty name to finish):');
        
        while (true) {
            $fieldName = $this->ask('Field name (or empty to finish)');
            if (empty($fieldName)) {
                break;
            }

            $fieldDesc = $this->ask("Description for '{$fieldName}'", "Enter {$fieldName}");
            $required = $this->confirm("Is '{$fieldName}' required?", true);
            
            $fields[$fieldName] = $fieldDesc . ($required ? ' | required' : '');
        }

        if (empty($fields)) {
            $fields = [
                'name' => 'Your name | required',
                'message' => 'Your message | required | min:10',
            ];
        }

        return new DataCollectorConfig(
            name: $name,
            title: $title,
            description: $description,
            fields: $fields,
            confirmBeforeComplete: true,
            allowEnhancement: true,
            onComplete: fn($data) => ['success' => true, 'data' => $data],
            locale: $locale,
            detectLocale: $detectLocale,
        );
    }
}
