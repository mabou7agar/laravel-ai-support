<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Fal;

use LaravelAIEngine\Exceptions\AIEngineException;

class FalReferencePackLookResolver
{
    private const LOOK_MODE_VENDOR = 'vendor';
    private const LOOK_MODE_GUIDED = 'guided';
    private const LOOK_MODE_STRICT_STORED = 'strict_stored';
    private const LOOK_MODE_STRICT_SELECTED_SET = 'strict_selected_set';

    private const LOOK_PRESETS = [
        'character' => [
            ['key' => 'signature', 'label' => 'Signature look', 'instruction' => 'Keep the same hairstyle, makeup, clothing, and accessories exactly the same as the base design.'],
            ['key' => 'beauty_variant', 'label' => 'Hair and makeup variant', 'instruction' => 'Create a fresh variant by changing hair styling and makeup while preserving the same face, identity, and overall silhouette.'],
            ['key' => 'fashion_variant', 'label' => 'Styled variant', 'instruction' => 'Create another styling variant with updated hair, makeup, and fashion styling while preserving the same identity.'],
            ['key' => 'cinematic_variant', 'label' => 'Cinematic variant', 'instruction' => 'Create a cinematic styling variant with a new beauty direction and polished wardrobe styling while preserving the same identity.'],
        ],
        'default' => [
            ['key' => 'signature', 'label' => 'Primary look', 'instruction' => 'Keep the exact same form, materials, silhouette, and proportions as the base design.'],
            ['key' => 'material_variant', 'label' => 'Material and color variant', 'instruction' => 'Create a new variant by changing material, finish, and color while preserving the same identity and silhouette.'],
            ['key' => 'styled_variant', 'label' => 'Styled variant', 'instruction' => 'Create another distinct design variant while preserving the same recognizable identity and proportions.'],
            ['key' => 'premium_variant', 'label' => 'Premium variant', 'instruction' => 'Create a premium design variation while preserving the same core identity and silhouette.'],
        ],
    ];

    public function lookPresets(string $entityType): array
    {
        return self::LOOK_PRESETS[$entityType] ?? self::LOOK_PRESETS['default'];
    }

    public function resolveSelectedLooks(array $options, string $entityType, ?array $baseReferencePack = null): array
    {
        $rawSelectedLooks = $options['selected_looks'] ?? null;

        if (is_array($rawSelectedLooks) && $rawSelectedLooks !== []) {
            $selectedLooks = $this->buildSelectedLooks($rawSelectedLooks, $entityType);

            if ($selectedLooks === []) {
                throw new AIEngineException('selected_looks must include at least one look with an id.');
            }

            return $selectedLooks;
        }

        $persistedSelectedLooks = $this->extractSelectedLooksFromReferencePack($baseReferencePack, $entityType);
        if ($persistedSelectedLooks !== []) {
            return $persistedSelectedLooks;
        }

        $selectedLook = $this->resolveSingleSelectedLook($options, $entityType, $baseReferencePack);

        return $selectedLook !== null ? [$selectedLook] : [];
    }

    public function resolveSelectedLook(array $options, string $entityType, ?array $baseReferencePack = null): ?array
    {
        return $this->resolvePrimarySelectedLook(
            $this->resolveSelectedLooks($options, $entityType, $baseReferencePack)
        );
    }

    public function resolveLookMode(array $options, array $selectedLooks = [], ?array $baseReferencePack = null): string
    {
        $requestedMode = $this->normalizeLookMode($options['look_mode'] ?? null);
        if ($requestedMode !== null) {
            return $requestedMode;
        }

        if (count($selectedLooks) > 1) {
            return self::LOOK_MODE_STRICT_SELECTED_SET;
        }

        if (array_key_exists('strict_stored_looks', $options)) {
            return (bool) $options['strict_stored_looks']
                ? self::LOOK_MODE_STRICT_STORED
                : ($selectedLooks !== [] ? self::LOOK_MODE_GUIDED : self::LOOK_MODE_VENDOR);
        }

        $storedMode = $this->extractStoredLookMode($baseReferencePack);
        if ($storedMode !== null) {
            return $storedMode;
        }

        return $selectedLooks !== [] ? self::LOOK_MODE_GUIDED : self::LOOK_MODE_VENDOR;
    }

    public function extractSelectedLooksFromReferencePack(?array $referencePack, string $entityType): array
    {
        if (!is_array($referencePack)) {
            return [];
        }

        $selectedLooks = data_get($referencePack, 'metadata.selected_looks');
        if (is_array($selectedLooks) && $selectedLooks !== []) {
            $normalizedLooks = $this->buildSelectedLooks($selectedLooks, $entityType);
            if ($normalizedLooks !== []) {
                return $normalizedLooks;
            }
        }

        $selectedLook = data_get($referencePack, 'metadata.selected_look');
        if (is_array($selectedLook)) {
            $lookPayload = is_array($selectedLook['payload'] ?? null) ? $selectedLook['payload'] : [];
            $lookPayload['look_label'] = $selectedLook['label'] ?? ($lookPayload['look_label'] ?? null);
            $lookPayload['look_instruction'] = $selectedLook['instruction'] ?? ($lookPayload['look_instruction'] ?? null);

            $lookId = $this->extractLookId($selectedLook['id'] ?? null, $lookPayload);
            if ($lookId !== null) {
                return [$this->buildSelectedLook($lookId, $lookPayload, $entityType)];
            }
        }

        $firstLook = data_get($referencePack, 'metadata.looks.0');
        if (!is_array($firstLook)) {
            return [];
        }

        $fallbackLookId = $this->extractLookId($firstLook['variant'] ?? null, [
            'look_label' => $firstLook['label'] ?? null,
        ]);

        return $fallbackLookId !== null
            ? [$this->buildSelectedLook($fallbackLookId, [
                'look_label' => $firstLook['label'] ?? null,
                'look_instruction' => $firstLook['instruction'] ?? null,
            ], $entityType)]
            : [];
    }

    public function resolvePrimarySelectedLook(array $selectedLooks): ?array
    {
        foreach ($selectedLooks as $selectedLook) {
            if (($selectedLook['is_primary'] ?? false) === true) {
                return $selectedLook;
            }
        }

        return $selectedLooks[0] ?? null;
    }

    public function serializeSelectedLook(?array $selectedLook, string $lookMode): ?array
    {
        if (!is_array($selectedLook)) {
            return null;
        }

        return [
            'id' => $selectedLook['id'] ?? $selectedLook['key'] ?? null,
            'variant' => $selectedLook['key'] ?? $selectedLook['id'] ?? null,
            'label' => $selectedLook['label'] ?? null,
            'instruction' => $selectedLook['instruction'] ?? null,
            'mode' => $lookMode,
            'collapse_workflow' => $lookMode === self::LOOK_MODE_STRICT_STORED,
            'is_primary' => (bool) ($selectedLook['is_primary'] ?? false),
            'payload' => is_array($selectedLook['payload'] ?? null) ? $selectedLook['payload'] : [],
        ];
    }

    public function serializeSelectedLooks(array $selectedLooks, string $lookMode): array
    {
        return array_values(array_filter(array_map(
            fn (array $selectedLook): ?array => $this->serializeSelectedLook($selectedLook, $lookMode),
            $selectedLooks
        )));
    }

    public function defaultLookInstruction(string $entityType, string $lookId): string
    {
        return $entityType === 'character'
            ? 'Keep the selected look "' . $this->humanizeLookId($lookId) . '" consistent across every requested view while preserving the same person and identity.'
            : 'Keep the selected look "' . $this->humanizeLookId($lookId) . '" consistent across every requested view while preserving the same design, proportions, and silhouette.';
    }

    private function resolveSingleSelectedLook(array $options, string $entityType, ?array $baseReferencePack = null): ?array
    {
        $lookPayload = $this->normalizeLookPayload($options['look_payload'] ?? null);
        $lookId = $this->extractLookId($options['look_id'] ?? null, $lookPayload);

        if ($lookId !== null) {
            return $this->buildSelectedLook($lookId, $lookPayload, $entityType);
        }

        return $this->extractSelectedLookFromReferencePack($baseReferencePack, $entityType);
    }

    private function normalizeLookMode(mixed $lookMode): ?string
    {
        if (!is_string($lookMode) || trim($lookMode) === '') {
            return null;
        }

        $normalized = trim($lookMode);

        return in_array($normalized, [
            self::LOOK_MODE_STRICT_SELECTED_SET,
            self::LOOK_MODE_STRICT_STORED,
            self::LOOK_MODE_GUIDED,
            self::LOOK_MODE_VENDOR,
        ], true) ? $normalized : null;
    }

    private function normalizeLookPayload(mixed $payload): array
    {
        return is_array($payload) ? $payload : [];
    }

    private function extractLookId(mixed $lookId, array $lookPayload = []): ?string
    {
        $candidates = [$lookId, $lookPayload['look_id'] ?? null, $lookPayload['id'] ?? null];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function buildSelectedLook(string $lookId, array $lookPayload, string $entityType): array
    {
        $preset = $this->resolveLookPresetById($lookId, $entityType);

        return [
            'id' => $lookId,
            'key' => $lookId,
            'label' => $this->firstNonEmptyString(
                $lookPayload['look_label'] ?? null,
                $lookPayload['label'] ?? null,
                $lookPayload['name'] ?? null,
                $preset['label'] ?? null,
                $this->humanizeLookId($lookId)
            ),
            'instruction' => $this->firstNonEmptyString(
                $lookPayload['look_instruction'] ?? null,
                $lookPayload['instruction'] ?? null,
                $preset['instruction'] ?? null,
                $this->defaultLookInstruction($entityType, $lookId)
            ),
            'payload' => $lookPayload,
        ];
    }

    private function buildSelectedLooks(array $rawSelectedLooks, string $entityType): array
    {
        $selectedLooks = [];

        foreach ($rawSelectedLooks as $rawSelectedLook) {
            if (!is_array($rawSelectedLook)) {
                continue;
            }

            $lookId = $this->extractLookId($rawSelectedLook['id'] ?? null, $rawSelectedLook);
            if ($lookId === null) {
                continue;
            }

            $lookPayload = is_array($rawSelectedLook['payload'] ?? null)
                ? array_merge($rawSelectedLook['payload'], array_filter([
                    'id' => $lookId,
                    'label' => $rawSelectedLook['label'] ?? null,
                    'name' => $rawSelectedLook['name'] ?? null,
                    'instruction' => $rawSelectedLook['instruction'] ?? null,
                    'is_primary' => $rawSelectedLook['is_primary'] ?? null,
                ], static fn (mixed $value): bool => $value !== null))
                : $rawSelectedLook;

            $look = $this->buildSelectedLook($lookId, $lookPayload, $entityType);
            if (($rawSelectedLook['is_primary'] ?? false) === true) {
                $look['is_primary'] = true;
            }

            $selectedLooks[] = $look;
        }

        return $selectedLooks;
    }

    private function resolveLookPresetById(string $lookId, string $entityType): ?array
    {
        foreach ($this->lookPresets($entityType) as $preset) {
            if (($preset['key'] ?? null) === $lookId) {
                return $preset;
            }
        }

        return null;
    }

    private function extractSelectedLookFromReferencePack(?array $referencePack, string $entityType): ?array
    {
        $selectedLooks = $this->extractSelectedLooksFromReferencePack($referencePack, $entityType);

        return $this->resolvePrimarySelectedLook($selectedLooks);
    }

    private function extractStoredLookMode(?array $referencePack): ?string
    {
        if (!is_array($referencePack)) {
            return null;
        }

        $metadataMode = $this->normalizeLookMode(data_get($referencePack, 'metadata.look_mode'));
        if ($metadataMode !== null) {
            return $metadataMode;
        }

        $selectedMode = $this->normalizeLookMode(data_get($referencePack, 'metadata.selected_look.mode'));
        if ($selectedMode !== null) {
            return $selectedMode;
        }

        $selectedSetMode = $this->normalizeLookMode(data_get($referencePack, 'metadata.selected_looks.0.mode'));
        if ($selectedSetMode !== null) {
            return $selectedSetMode;
        }

        if (data_get($referencePack, 'metadata.selected_look.collapse_workflow') === true) {
            return self::LOOK_MODE_STRICT_STORED;
        }

        return null;
    }

    private function firstNonEmptyString(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function humanizeLookId(string $lookId): string
    {
        return ucwords(str_replace(['_', '-'], ' ', trim($lookId)));
    }
}
