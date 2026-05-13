<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK;

use LaravelAIEngine\DTOs\RealtimeSessionConfig;

class RealtimeSessionService
{
    public function create(RealtimeSessionConfig|array $config): array
    {
        $config = $config instanceof RealtimeSessionConfig
            ? $config
            : new RealtimeSessionConfig(
                provider: (string) ($config['provider'] ?? 'openai'),
                model: (string) ($config['model'] ?? 'gpt-4o-realtime-preview'),
                modalities: (array) ($config['modalities'] ?? ['text', 'audio']),
                voice: $config['voice'] ?? null,
                instructions: $config['instructions'] ?? null,
                tools: (array) ($config['tools'] ?? []),
                metadata: (array) ($config['metadata'] ?? [])
            );

        return match (strtolower($config->provider)) {
            'openai' => $this->openAISession($config),
            'gemini' => $this->geminiLiveSession($config),
            default => throw new \InvalidArgumentException("Realtime provider [{$config->provider}] is not supported."),
        };
    }

    protected function openAISession(RealtimeSessionConfig $config): array
    {
        return [
            'provider' => 'openai',
            'endpoint' => '/v1/realtime/sessions',
            'payload' => array_filter([
                'model' => $config->model,
                'modalities' => $config->modalities,
                'voice' => $config->voice,
                'instructions' => $config->instructions,
                'tools' => $config->tools,
                'metadata' => $config->metadata,
            ], static fn ($value): bool => $value !== null && $value !== []),
        ];
    }

    protected function geminiLiveSession(RealtimeSessionConfig $config): array
    {
        return [
            'provider' => 'gemini',
            'endpoint' => 'wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContent',
            'payload' => array_filter([
                'model' => $config->model,
                'response_modalities' => $config->modalities,
                'system_instruction' => $config->instructions,
                'tools' => $config->tools,
                'metadata' => $config->metadata,
            ], static fn ($value): bool => $value !== null && $value !== []),
        ];
    }
}
