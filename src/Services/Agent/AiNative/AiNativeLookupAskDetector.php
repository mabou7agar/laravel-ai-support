<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

class AiNativeLookupAskDetector
{
    /**
     * @param array<string, mixed> $plan
     */
    public function asksForResolvableLookupValue(array $plan): bool
    {
        if ($this->hasEntityLikeRequiredInput($plan)) {
            return true;
        }

        $haystack = mb_strtolower((string) ($plan['message'] ?? ''));
        foreach ((array) config('ai-agent.ai_native.lookup_before_ask_terms', []) as $term) {
            $term = mb_strtolower(trim((string) $term));
            if ($term !== '' && preg_match('/(?<![\pL\pN_])'.preg_quote($term, '/').'(?![\pL\pN_])/iu', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function hasEntityLikeRequiredInput(array $plan): bool
    {
        foreach ((array) ($plan['required_inputs'] ?? []) as $input) {
            if (is_string($input)) {
                if ($this->fieldLooksLookupBacked($input)) {
                    return true;
                }

                continue;
            }

            if (!is_array($input)) {
                continue;
            }

            $name = (string) ($input['name'] ?? $input['field'] ?? '');
            $type = mb_strtolower((string) ($input['type'] ?? ''));
            if ($this->fieldLooksLookupBacked($name) || in_array($type, ['select', 'search', 'lookup', 'entity', 'relation', 'model'], true)) {
                return true;
            }
        }

        return false;
    }

    private function fieldLooksLookupBacked(string $field): bool
    {
        $field = mb_strtolower(trim($field));
        if ($field === '') {
            return false;
        }

        if (in_array($field, ['id', 'uuid', 'name', 'title', 'label', 'email', 'number', 'code', 'query'], true)) {
            return true;
        }

        foreach (['_id', '_uuid', '_name', '_title', '_label', '_email', '_number', '_code', '_slug'] as $suffix) {
            if (str_ends_with($field, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
