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
                metadata: (array) ($config['metadata'] ?? []),
                inputAudioFormat: $config['input_audio_format'] ?? $config['inputAudioFormat'] ?? null,
                outputAudioFormat: $config['output_audio_format'] ?? $config['outputAudioFormat'] ?? null,
                turnDetection: (array) ($config['turn_detection'] ?? $config['turnDetection'] ?? []),
                temperature: isset($config['temperature']) ? (float) $config['temperature'] : null,
                maxResponseOutputTokens: $config['max_response_output_tokens'] ?? $config['maxResponseOutputTokens'] ?? null,
                providerOptions: (array) ($config['provider_options'] ?? $config['providerOptions'] ?? [])
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
                'input_audio_format' => $config->inputAudioFormat,
                'output_audio_format' => $config->outputAudioFormat,
                'turn_detection' => $config->turnDetection,
                'temperature' => $config->temperature,
                'max_response_output_tokens' => $config->maxResponseOutputTokens,
            ], static fn ($value): bool => $value !== null && $value !== []),
        ];
    }

    protected function geminiLiveSession(RealtimeSessionConfig $config): array
    {
        return [
                'provider' => 'gemini',
                'endpoint' => 'wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContent',
            'payload' => array_filter(array_replace_recursive([
                'model' => $config->model,
                'responseModalities' => $config->modalities,
                'systemInstruction' => $config->instructions !== null ? [
                    'parts' => [['text' => $config->instructions]],
                ] : null,
                'tools' => $config->tools,
                'metadata' => $config->metadata,
                'inputAudioFormat' => $config->inputAudioFormat,
                'outputAudioFormat' => $config->outputAudioFormat,
                'realtimeInputConfig' => $config->turnDetection !== [] ? [
                    'turnDetection' => $config->turnDetection,
                ] : null,
                'generationConfig' => array_filter([
                    'temperature' => $config->temperature,
                    'maxOutputTokens' => is_int($config->maxResponseOutputTokens) ? $config->maxResponseOutputTokens : null,
                ], static fn ($value): bool => $value !== null && $value !== []),
            ], $config->providerOptions), static fn ($value): bool => $value !== null && $value !== []),
        ];
    }
}
