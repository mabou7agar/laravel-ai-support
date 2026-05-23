<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

class AiNativeLookupLabelResolver
{
    /**
     * @param array<string, mixed> $payload
     */
    public function label(array $payload): string
    {
        foreach (['name', 'title', 'label', 'email', 'number', 'code', 'query'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return $this->normalize((string) $value);
            }
        }

        foreach ($payload as $key => $value) {
            if (!is_string($key) || !is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            foreach (['_name', '_title', '_label', '_email', '_number', '_code', '_slug'] as $suffix) {
                if (str_ends_with($key, $suffix)) {
                    return $this->normalize((string) $value);
                }
            }
        }

        return '';
    }

    public function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
