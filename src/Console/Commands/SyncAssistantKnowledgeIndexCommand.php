<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Contracts\ScopedKnowledgeIndex;
use LaravelAIEngine\Contracts\SynchronizesScopedKnowledgeIndex;
use LaravelAIEngine\Services\Knowledge\KnowledgeSourceRegistry;
use Throwable;

final class SyncAssistantKnowledgeIndexCommand extends Command
{
    protected $signature = 'ai:assistant-knowledge-index
                            {--tenant= : Trusted tenant identifier}
                            {--workspace= : Trusted workspace identifier}
                            {--user= : Trusted user identifier}
                            {--subscription-active : Include subscription-limited documents}
                            {--force : Re-embed documents even when fingerprints are unchanged}
                            {--json : Print a machine-readable report}';

    protected $description = 'Synchronize authorized assistant knowledge sources into the configured persistent index.';

    public function handle(KnowledgeSourceRegistry $sources, ScopedKnowledgeIndex $index): int
    {
        if (!$index instanceof SynchronizesScopedKnowledgeIndex) {
            $this->error(sprintf(
                'The active scoped knowledge index [%s] is not persistent.',
                $index::class,
            ));

            return self::FAILURE;
        }

        $context = array_filter([
            'tenant_id' => $this->option('tenant'),
            'workspace_id' => $this->option('workspace'),
            'user_id' => $this->option('user'),
            'subscription_active' => (bool) $this->option('subscription-active'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        try {
            $documents = $sources->documents($context);
            $indexed = $index->sync($documents, (bool) $this->option('force'));
            $payload = [
                'success' => true,
                'index' => $index::class,
                'documents' => count($documents),
                'indexed' => $indexed,
                'scope' => $context,
            ];
        } catch (Throwable $exception) {
            $payload = [
                'success' => false,
                'index' => $index::class,
                'error' => $exception->getMessage(),
                'error_class' => $exception::class,
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } elseif ($payload['success']) {
            $this->info(sprintf(
                'Assistant knowledge synchronized: %d document(s), %d indexed.',
                $payload['documents'],
                $payload['indexed'],
            ));
        } else {
            $this->error((string) $payload['error']);
        }

        return $payload['success'] ? self::SUCCESS : self::FAILURE;
    }
}
