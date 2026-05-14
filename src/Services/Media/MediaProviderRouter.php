<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Media;

class MediaProviderRouter
{
    /**
     * Select a provider/model for a media capability.
     *
     * @return array{provider:string, model:string, estimated_unit_cost:float, metadata:array}
     */
    public function select(string $capability, string $mode = 'balanced', array $constraints = []): array
    {
        $candidates = $this->candidates($capability, $constraints);

        if ($candidates === []) {
            throw new \InvalidArgumentException("No enabled media provider supports [{$capability}].");
        }

        $mode = strtolower($mode);
        usort($candidates, function (array $a, array $b) use ($mode): int {
            return match ($mode) {
                'local', 'free' => [$a['estimated_unit_cost'], $b['quality_score'] ?? 0] <=> [$b['estimated_unit_cost'], $a['quality_score'] ?? 0],
                'quality' => [-(float) ($a['quality_score'] ?? 0), $a['estimated_unit_cost']] <=> [-(float) ($b['quality_score'] ?? 0), $b['estimated_unit_cost']],
                'fast' => [(float) ($a['latency_score'] ?? 999), $a['estimated_unit_cost']] <=> [(float) ($b['latency_score'] ?? 999), $b['estimated_unit_cost']],
                'cheapest' => [$a['estimated_unit_cost'], -(float) ($a['quality_score'] ?? 0)] <=> [$b['estimated_unit_cost'], -(float) ($b['quality_score'] ?? 0)],
                default => [$a['estimated_unit_cost'] / max(1.0, (float) ($a['quality_score'] ?? 1)), $a['estimated_unit_cost']]
                    <=> [$b['estimated_unit_cost'] / max(1.0, (float) ($b['quality_score'] ?? 1)), $b['estimated_unit_cost']],
            };
        });

        return $candidates[0];
    }

    /**
     * @return array<int, array{provider:string, model:string, estimated_unit_cost:float, metadata:array}>
     */
    public function candidates(string $capability, array $constraints = []): array
    {
        $providers = (array) config('ai-engine.media_routing.providers', []);
        $candidates = [];

        foreach ($providers as $provider => $config) {
            if (($config['enabled'] ?? true) === false) {
                continue;
            }

            $model = $config['models'][$capability] ?? null;
            if (!is_array($model) || empty($model['model'])) {
                continue;
            }

            if (($constraints['allow_local'] ?? true) === false && (($model['local'] ?? false) === true || $provider === 'comfyui')) {
                continue;
            }

            $candidates[] = [
                'provider' => (string) $provider,
                'model' => (string) $model['model'],
                'estimated_unit_cost' => (float) ($model['estimated_unit_cost'] ?? INF),
                'quality_score' => (float) ($model['quality_score'] ?? 1.0),
                'latency_score' => (float) ($model['latency_score'] ?? 999.0),
                'metadata' => $model,
            ];
        }

        return $candidates;
    }
}
