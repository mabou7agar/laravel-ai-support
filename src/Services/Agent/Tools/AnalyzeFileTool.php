<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\FileAnalysisService;

/**
 * Generic, entity-agnostic file intake: extract the text of a previously-stored upload and
 * suggest the create action(s) it implies — "upload an invoice -> suggest create_invoice",
 * "upload a customer list -> suggest create_customer", etc. Suggestions are pure config
 * (ai-engine.file_analysis.keyword_suggestions: pattern -> action), so this is never tied to
 * one entity.
 *
 * Security: only reads files inside the configured sandbox directory
 * (ai-engine.file_analysis.base_path), with an extension allowlist and a size cap, so a
 * planner-supplied path can never read arbitrary files (no LFI). Suggested actions are
 * filtered to create tools that actually exist in the registry.
 */
class AnalyzeFileTool extends AgentTool
{
    public function __construct(
        protected ?FileAnalysisService $files = null,
        protected ?ToolRegistry $registry = null
    ) {
    }

    public function getName(): string
    {
        return 'analyze_file';
    }

    public function getDescription(): string
    {
        return 'Read a previously-uploaded file (PDF, Word, text, or spreadsheet) and suggest '
            . 'what to do with it — e.g. an uploaded invoice suggests creating an invoice, a '
            . 'customer list suggests creating customers. Returns the extracted text and the '
            . 'suggested create actions. Use after a file has been uploaded and stored.';
    }

    public function getParameters(): array
    {
        return [
            'path' => [
                'type' => 'string',
                'description' => 'Path of the stored upload, relative to the configured upload directory (e.g. "uploads/inv-123.pdf").',
                'required' => true,
            ],
            'original_name' => [
                'type' => 'string',
                'description' => 'Original filename, for context (optional).',
                'required' => false,
            ],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $resolved = $this->resolvePath((string) ($parameters['path'] ?? ''));
        if ($resolved === null) {
            return ActionResult::failure('That file path is not allowed or does not exist.', ['analyzed' => false]);
        }

        $extension = strtolower((string) pathinfo($resolved, PATHINFO_EXTENSION));
        if (!in_array($extension, $this->allowedExtensions(), true)) {
            return ActionResult::failure("Files of type '{$extension}' are not supported here.", ['analyzed' => false]);
        }

        $maxBytes = (int) config('ai-engine.file_analysis.max_bytes', 10 * 1024 * 1024);
        if (($size = @filesize($resolved)) === false || $size > $maxBytes) {
            return ActionResult::failure('That file is too large to analyze.', ['analyzed' => false]);
        }

        $name = (string) ($parameters['original_name'] ?? basename($resolved));

        try {
            $result = $this->fileService()->extractAndSuggestFromPath($resolved, $extension, $name);
        } catch (\Throwable $e) {
            return ActionResult::failure('The file could not be read.', ['analyzed' => false]);
        }

        $content = (string) ($result['content'] ?? '');
        $suggestions = $this->withRegisteredActionsOnly((array) ($result['suggestions'] ?? []));

        $message = $suggestions === []
            ? sprintf('Read %s (%d characters). No specific action was detected.', $name, mb_strlen($content))
            : sprintf('Read %s. Suggested: %s.', $name, implode(', ', array_map(
                static fn (array $s): string => (string) ($s['action_label'] ?: $s['action_id']),
                $suggestions
            )));

        return ActionResult::success($message, [
            'analyzed' => true,
            'file' => $name,
            'characters' => mb_strlen($content),
            'content_preview' => mb_substr($content, 0, (int) config('ai-engine.file_analysis.preview_chars', 1500)),
            'suggestions' => array_values($suggestions),
        ], ['tool' => $this->getName()]);
    }

    /**
     * Resolve a caller-supplied path to a real file inside the sandbox dir, or null.
     */
    private function resolvePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $base = (string) config('ai-engine.file_analysis.base_path', storage_path('app/uploads'));
        $baseReal = realpath($base);
        if ($baseReal === false) {
            return null;
        }

        // Treat absolute paths as-is, relative paths as relative to the sandbox base.
        $candidate = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : $baseReal . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            return null;
        }

        // Must live inside the sandbox (defeats ../ traversal).
        if ($real !== $baseReal && !str_starts_with($real, $baseReal . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    /**
     * @return array<int, string>
     */
    private function allowedExtensions(): array
    {
        $configured = (array) config('ai-engine.file_analysis.allowed_extensions', []);
        if ($configured !== []) {
            return array_map('strtolower', $configured);
        }

        return ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt', 'xls', 'xlsx', 'csv'];
    }

    /**
     * Keep only suggestions whose action_id is a registered tool (so we never suggest a
     * create action the app doesn't actually have). Disable with validate_actions => false.
     *
     * @param array<int, array<string, mixed>> $suggestions
     * @return array<int, array<string, mixed>>
     */
    private function withRegisteredActionsOnly(array $suggestions): array
    {
        if (!(bool) config('ai-engine.file_analysis.validate_actions', true)) {
            return $suggestions;
        }

        $registry = $this->registry ?? app(ToolRegistry::class);

        return array_values(array_filter($suggestions, static function (array $s) use ($registry): bool {
            $action = (string) ($s['action_id'] ?? '');

            return $action !== '' && $registry->has($action);
        }));
    }

    private function fileService(): FileAnalysisService
    {
        return $this->files ?? app(FileAnalysisService::class);
    }
}
