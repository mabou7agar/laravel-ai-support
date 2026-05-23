<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use LaravelAIEngine\DTOs\LearnedDesignGenerationRequest;
use LaravelAIEngine\Services\Learning\LearnedDesignGeneratorService;

class GenerateLearnedDesignCommand extends Command
{
    protected $signature = 'ai:design
                            {prompt : What should be designed using learned context}
                            {--type=design : Learned knowledge type to retrieve}
                            {--format=html : html|markdown}
                            {--output= : Output file path; defaults to storage/app/ai-engine/learned-designs}
                            {--user= : Scope user id}
                            {--tenant= : Scope tenant id}
                            {--workspace= : Scope workspace id}
                            {--session= : Scope session id}
                            {--engine= : AI engine override}
                            {--model= : AI model override}
                            {--limit=5 : Number of learned matches to include}
                            {--max-tokens=2500 : Max output tokens}
                            {--temperature=0.25 : Generation temperature}
                            {--source-context-chars=12000 : Max raw learned source characters to include}
                            {--media-url= : Optional neutral image URL for learned designs that use full-bleed media bands}
                            {--raw : Use the provider HTML directly without package composition}
                            {--json : Output JSON result}';

    protected $description = 'Generate a design artifact from learned package context';

    public function handle(LearnedDesignGeneratorService $generator): int
    {
        $format = strtolower((string) $this->option('format'));
        $format = in_array($format, ['html', 'markdown'], true) ? $format : 'html';

        $result = $generator->generate(new LearnedDesignGenerationRequest(
            prompt: (string) $this->argument('prompt'),
            scope: $this->scope(),
            type: (string) $this->option('type'),
            format: $format,
            limit: (int) $this->option('limit'),
            engine: $this->option('engine') ? (string) $this->option('engine') : null,
            model: $this->option('model') ? (string) $this->option('model') : null,
            maxTokens: (int) $this->option('max-tokens'),
            temperature: (float) $this->option('temperature'),
            sourceContextChars: (int) $this->option('source-context-chars'),
            composeHtml: !(bool) $this->option('raw'),
            mediaUrl: $this->option('media-url') ? (string) $this->option('media-url') : null,
        ));

        $output = $this->outputPath($format);
        File::ensureDirectoryExists(dirname($output));
        File::put($output, $result->content);

        if ($this->option('json')) {
            $this->line(json_encode([
                ...$result->toArray(),
                'output' => $output,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Learned design generated.');
        $this->table(['Field', 'Value'], [
            ['Output', $output],
            ['Format', $result->format],
            ['Engine', $result->engine],
            ['Model', $result->model],
            ['Matches', (string) count($result->matches)],
            ['Tokens', (string) ($result->tokensUsed ?? 0)],
            ['Credits', (string) ($result->creditsUsed ?? 0.0)],
        ]);

        return self::SUCCESS;
    }

    protected function scope(): array
    {
        return [
            'user_id' => $this->option('user') ?: null,
            'tenant_id' => $this->option('tenant') ?: null,
            'workspace_id' => $this->option('workspace') ?: null,
            'session_id' => $this->option('session') ?: null,
        ];
    }

    protected function outputPath(string $format): string
    {
        $output = $this->option('output');
        if (is_string($output) && $output !== '') {
            return $output;
        }

        $extension = $format === 'markdown' ? 'md' : 'html';

        return storage_path('app/ai-engine/learned-designs/' . now()->format('Ymd_His') . '.' . $extension);
    }
}
