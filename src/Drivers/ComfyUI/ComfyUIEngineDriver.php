<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\ComfyUI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\AIEngineException;

class ComfyUIEngineDriver extends BaseEngineDriver
{
    private Client $httpClient;

    public function __construct(array $config, ?Client $httpClient = null)
    {
        parent::__construct($config);

        $this->httpClient = $httpClient ?? new Client([
            'timeout' => $this->getTimeout(),
            'base_uri' => rtrim($this->getBaseUrl(), '/').'/',
            'headers' => $this->buildHeaders(),
        ]);
    }

    public function generate(AIRequest $request): AIResponse
    {
        return match ($request->getModel()->getContentType()) {
            'image', 'video', 'audio' => $this->runWorkflow($request),
            default => throw new AIEngineException('ComfyUI driver supports local media workflows only.'),
        };
    }

    public function stream(AIRequest $request): \Generator
    {
        yield $this->generate($request)->getContent();
    }

    public function validateRequest(AIRequest $request): bool
    {
        return $this->getBaseUrl() !== '';
    }

    public function getEngine(): EngineEnum
    {
        return EngineEnum::from(EngineEnum::COMFYUI);
    }

    public function getAvailableModels(): array
    {
        return [
            EntityEnum::COMFYUI_DEFAULT_IMAGE => ['name' => 'Default Image Workflow', 'type' => 'image'],
            EntityEnum::COMFYUI_DEFAULT_VIDEO => ['name' => 'Default Video Workflow', 'type' => 'video'],
        ];
    }

    public function generateJsonAnalysis(string $prompt, string $systemPrompt, ?string $model = null, int $maxTokens = 300): string
    {
        throw new AIEngineException('ComfyUI media driver does not support JSON analysis.');
    }

    public function generateImage(AIRequest $request): AIResponse
    {
        return $this->runWorkflow($request);
    }

    public function generateVideo(AIRequest $request): AIResponse
    {
        return $this->runWorkflow($request);
    }

    public function generateAudio(AIRequest $request): AIResponse
    {
        return $this->runWorkflow($request);
    }

    protected function runWorkflow(AIRequest $request): AIResponse
    {
        try {
            $workflow = $request->getParameters()['workflow'] ?? $this->config['default_workflow'] ?? null;
            if (!is_array($workflow)) {
                throw new AIEngineException('ComfyUI requires a workflow array or ai-engine.engines.comfyui.default_workflow.');
            }

            $workflow = $this->replacePromptPlaceholders($workflow, $request);
            $response = $this->httpClient->post('prompt', [
                'json' => [
                    'prompt' => $workflow,
                    'client_id' => $request->getParameters()['client_id'] ?? 'laravel-ai-engine',
                ],
            ]);

            $submitted = json_decode($response->getBody()->getContents(), true) ?: [];
            $promptId = (string) ($submitted['prompt_id'] ?? '');

            if (($request->getParameters()['async'] ?? false) === true || $promptId === '') {
                return AIResponse::success('', $request->getEngine(), $request->getModel(), [
                    'provider' => EngineEnum::COMFYUI,
                    'prompt_id' => $promptId,
                    'status' => 'submitted',
                    'cost_tier' => 'local',
                ]);
            }

            $history = $this->fetchHistory($promptId);
            $files = $this->extractHistoryFiles($promptId, $history);

            return AIResponse::success('', $request->getEngine(), $request->getModel(), [
                'provider' => EngineEnum::COMFYUI,
                'prompt_id' => $promptId,
                'cost_tier' => 'local',
            ])->withFiles($files)->withUsage(creditsUsed: 0.0);
        } catch (RequestException $e) {
            return AIResponse::error('ComfyUI workflow failed: '.$e->getMessage(), $request->getEngine(), $request->getModel());
        }
    }

    protected function fetchHistory(string $promptId): array
    {
        $response = $this->httpClient->get('history/'.rawurlencode($promptId));

        return json_decode($response->getBody()->getContents(), true) ?: [];
    }

    protected function extractHistoryFiles(string $promptId, array $history): array
    {
        $record = $history[$promptId] ?? $history;
        $outputs = (array) ($record['outputs'] ?? []);
        $files = [];

        foreach ($outputs as $output) {
            foreach (['images', 'videos', 'audio'] as $field) {
                foreach ((array) ($output[$field] ?? []) as $file) {
                    if (!is_array($file) || empty($file['filename'])) {
                        continue;
                    }

                    $query = http_build_query(array_filter([
                        'filename' => $file['filename'],
                        'subfolder' => $file['subfolder'] ?? '',
                        'type' => $file['type'] ?? 'output',
                    ], static fn ($value): bool => $value !== null));

                    $files[] = rtrim($this->getBaseUrl(), '/').'/view?'.$query;
                }
            }
        }

        return $files;
    }

    protected function replacePromptPlaceholders(array $workflow, AIRequest $request): array
    {
        array_walk_recursive($workflow, function (&$value) use ($request): void {
            if (is_string($value)) {
                $value = str_replace('{{prompt}}', $request->getPrompt(), $value);
            }
        });

        return $workflow;
    }

    protected function getSupportedCapabilities(): array
    {
        return ['image', 'images', 'video', 'audio'];
    }

    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::from(EngineEnum::COMFYUI);
    }

    protected function getDefaultModel(): EntityEnum
    {
        return new EntityEnum(EntityEnum::COMFYUI_DEFAULT_IMAGE);
    }

    protected function validateConfig(): void
    {
    }

    protected function getBaseUrl(): string
    {
        return (string) ($this->config['base_url'] ?? 'http://127.0.0.1:8188');
    }
}
