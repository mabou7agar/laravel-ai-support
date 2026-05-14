<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Collectors;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AutonomousCollectorConfig;

class CollectorSummaryRenderer
{
    public function generateSummary(
        array $data,
        int $depth = 0,
        ?AutonomousCollectorConfig $config = null,
        array $toolResults = []
    ): string {
        $lines = [];
        $indent = str_repeat('  ', $depth);
        $entityDetails = $this->fetchEntityDetails($data, $config, $toolResults);
        $entities = [];
        $changes = [];
        $items = [];

        foreach ($data as $key => $value) {
            if (str_starts_with((string) $key, '_') || !$this->hasDisplayValue($value)) {
                continue;
            }

            if (str_ends_with((string) $key, '_user_id')) {
                $baseEntity = str_replace('_user_id', '', (string) $key) . '_id';
                if (array_key_exists($baseEntity, $data) && $this->hasDisplayValue($data[$baseEntity] ?? null)) {
                    continue;
                }
            }

            if (str_ends_with((string) $key, '_id')) {
                $entityType = str_replace('_id', '', (string) $key);
                $entities[(string) $key] = $entityDetails[$key] ?? ['ID' => $value, '_entity_type' => $entityType];
            } elseif (is_array($value) && isset($value[0])) {
                $items[(string) $key] = $value;
            } else {
                $changes[(string) $key] = $value;
            }
        }

        foreach ($entities as $key => $details) {
            $entityType = str_replace('_id', '', (string) $key);
            unset($details['_entity_type']);
            $icon = $this->getEntityIcon($entityType, $config);
            $prefix = $icon !== '' ? "{$icon} " : '';
            $lines[] = "{$indent}{$prefix}**" . ucwords(str_replace('_', ' ', $entityType)) . "**:";
            foreach ($details as $field => $value) {
                $lines[] = "{$indent}  • **{$field}**: {$value}";
            }
        }

        foreach ($changes as $key => $value) {
            $label = $this->formatFieldLabel($key);
            if (is_array($value)) {
                $icon = $this->sectionIcon('nested', $config);
                $prefix = $icon !== '' ? "{$icon} " : '';
                $lines[] = "{$indent}{$prefix}**{$label}**:";
                $nested = $this->generateSummary($value, $depth + 1, $config, $toolResults);
                if ($nested !== '') {
                    $lines[] = $nested;
                }
            } else {
                $icon = $this->sectionIcon('scalar', $config);
                $prefix = $icon !== '' ? "{$icon} " : '';
                $lines[] = "{$indent}{$prefix}**{$label}**: " . $this->formatScalarValue($key, $value, $config);
            }
        }

        foreach ($items as $key => $value) {
            $label = $this->formatFieldLabel($key);
            $icon = $this->sectionIcon('collection', $config);
            $prefix = $icon !== '' ? "{$icon} " : '';
            $lines[] = "{$indent}{$prefix}**{$label}**: " . count($value) . " item(s)";
            foreach ($value as $item) {
                if (is_array($item)) {
                    $itemSummary = $this->buildItemSummary($item, $config, (string) $key);
                    if ($itemSummary !== '') {
                        $lines[] = "{$indent}  • {$itemSummary}";
                    }
                } else {
                    $lines[] = "{$indent}  • {$item}";
                }
            }
        }

        return implode("\n", $lines);
    }

    public function buildSuccessMessage(mixed $result, array $collectedData, AutonomousCollectorConfig $config): string
    {
        if (is_array($result) && isset($result['message'])) {
            return (string) $result['message'];
        }

        $message = "✅ **{$config->goal} - Completed Successfully!**\n\n";

        if (is_array($result)) {
            foreach ($this->displayableScalars($result, $config) as $key => $value) {
                $message .= "**{$this->formatFieldLabel($key)}:** {$this->formatScalarValue($key, $value, $config)}\n";
            }
        }

        $summary = $this->generateSummary($collectedData, 0, $config);
        if ($summary !== '') {
            $message .= "\n{$summary}";
        }

        return $message;
    }

    public function fetchEntityDetails(
        array $data,
        ?AutonomousCollectorConfig $config = null,
        array $toolResults = []
    ): array {
        $details = [];
        $entityResolvers = $config?->entityResolvers ?? [];
        $toolLookup = $this->buildEntityLookupFromToolResults($toolResults, $config);

        foreach ($data as $key => $value) {
            if (!str_ends_with((string) $key, '_id') || !$this->hasDisplayValue($value)) {
                continue;
            }

            if (isset($entityResolvers[$key])) {
                try {
                    $resolver = $entityResolvers[$key];
                    if (is_callable($resolver)) {
                        $entityData = $resolver($value);
                        if (is_array($entityData) && $entityData !== []) {
                            $details[$key] = $entityData;
                        }
                    }
                } catch (\Throwable $exception) {
                    Log::warning('Autonomous collector entity resolver failed', [
                        'field' => $key,
                        'value' => $value,
                        'error' => $exception->getMessage(),
                    ]);
                }

                continue;
            }

            $lookupValue = $toolLookup[$key][(string) $value] ?? null;
            if (is_array($lookupValue) && $lookupValue !== []) {
                $details[$key] = $lookupValue;
            }
        }

        return $details;
    }

    public function buildEntityLookupFromToolResults(array $toolResults, ?AutonomousCollectorConfig $config = null): array
    {
        $lookup = [];
        $profileFields = $this->displayConfig($config)['entity_profile_fields'] ?? [];

        foreach ($toolResults as $entry) {
            if (!is_array($entry) || (($entry['success'] ?? false) !== true)) {
                continue;
            }

            $tool = strtolower((string) ($entry['tool'] ?? ''));
            $result = $entry['result'] ?? null;
            if (!is_array($result)) {
                continue;
            }

            $rows = $this->extractRows($result);
            $entityNames = $this->entityNamesFromTool($tool);

            foreach ($rows as $row) {
                $profile = [];
                foreach ($profileFields as $source => $label) {
                    if (is_int($source)) {
                        $source = (string) $label;
                        $label = $this->formatFieldLabel($source);
                    }

                    $source = (string) $source;
                    $value = trim((string) ($row[$source] ?? ''));
                    if ($value !== '') {
                        $profile[(string) $label] = $value;
                    }
                }

                if ($profile === []) {
                    continue;
                }

                foreach ($entityNames as $entityName) {
                    if (isset($row['id'])) {
                        $lookup[$entityName . '_id'][(string) $row['id']] = $profile;
                    }
                    if (isset($row['user_id'])) {
                        $lookup[$entityName . '_user_id'][(string) $row['user_id']] = $profile;
                    }
                }
            }
        }

        return $lookup;
    }

    public function hasDisplayValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    public function formatFieldLabel(string $field): string
    {
        return ucwords(str_replace('_', ' ', $field));
    }

    public function formatScalarValue(string $key, mixed $value, ?AutonomousCollectorConfig $config = null): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_numeric($value)) {
            $display = $this->displayConfig($config);
            if ($this->matchesConfiguredField($key, $display['decimal_fields'] ?? [])) {
                $decimals = (int) ($display['decimal_places'] ?? 2);

                return number_format((float) $value, max(0, $decimals), '.', '');
            }

            return (string) $value;
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    public function buildItemSummary(
        array $item,
        ?AutonomousCollectorConfig $config = null,
        ?string $collectionKey = null
    ): string {
        $itemDisplay = $this->itemDisplayConfig($config, $collectionKey);
        $label = $this->firstConfiguredValue($item, (array) ($itemDisplay['label_fields'] ?? []));
        $quantityField = $itemDisplay['quantity_field'] ?? null;
        $unitValueField = $itemDisplay['unit_value_field'] ?? null;
        $totalField = $itemDisplay['total_field'] ?? null;

        if (
            $label !== ''
            && is_string($quantityField)
            && is_string($unitValueField)
            && $this->hasDisplayValue($item[$quantityField] ?? null)
            && $this->hasDisplayValue($item[$unitValueField] ?? null)
        ) {
            $summary = $label . ' × ' . $this->formatScalarValue($quantityField, $item[$quantityField], $config);
            $summary .= ' @ ' . $this->formatScalarValue($unitValueField, $item[$unitValueField], $config);
            if (is_string($totalField) && $this->hasDisplayValue($item[$totalField] ?? null)) {
                $summary .= ' = ' . $this->formatScalarValue($totalField, $item[$totalField], $config);
            }

            return $summary;
        }

        $parts = [];
        foreach ($item as $key => $value) {
            if (str_starts_with((string) $key, '_') || !$this->hasDisplayValue($value) || is_array($value)) {
                continue;
            }

            $parts[] = $this->formatFieldLabel((string) $key) . ': ' . $this->formatScalarValue((string) $key, $value, $config);
        }

        return implode(', ', $parts);
    }

    public function getEntityIcon(string $entityType, ?AutonomousCollectorConfig $config = null): string
    {
        $display = $this->displayConfig($config);
        $icons = (array) ($display['entity_icons'] ?? []);

        return (string) ($icons[$entityType] ?? $display['default_entity_icon'] ?? '');
    }

    protected function displayableScalars(array $data, ?AutonomousCollectorConfig $config = null): array
    {
        $scalars = [];
        $limit = (int) ($this->displayConfig($config)['success_scalar_limit'] ?? 0);

        foreach ($data as $key => $value) {
            if ($limit > 0 && count($scalars) >= $limit) {
                break;
            }

            if (str_starts_with((string) $key, '_') || is_array($value) || !$this->hasDisplayValue($value)) {
                continue;
            }

            $scalars[(string) $key] = $value;
        }

        return $scalars;
    }

    protected function displayConfig(?AutonomousCollectorConfig $config): array
    {
        $display = $config?->context['collector_display'] ?? [];
        if (!is_array($display)) {
            return [];
        }

        return $display;
    }

    protected function itemDisplayConfig(?AutonomousCollectorConfig $config, ?string $collectionKey): array
    {
        $display = $this->displayConfig($config);
        $collections = (array) ($display['item_summaries'] ?? []);

        if ($collectionKey !== null && isset($collections[$collectionKey]) && is_array($collections[$collectionKey])) {
            return $collections[$collectionKey];
        }

        return is_array($display['item_summary'] ?? null) ? $display['item_summary'] : [];
    }

    protected function firstConfiguredValue(array $item, array $fields): string
    {
        foreach ($fields as $field) {
            if (!is_string($field) || !$this->hasDisplayValue($item[$field] ?? null)) {
                continue;
            }

            return trim((string) $item[$field]);
        }

        return '';
    }

    protected function sectionIcon(string $section, ?AutonomousCollectorConfig $config): string
    {
        $icons = (array) ($this->displayConfig($config)['section_icons'] ?? []);

        return (string) ($icons[$section] ?? '');
    }

    protected function matchesConfiguredField(string $key, array $fields): bool
    {
        foreach ($fields as $field) {
            if (is_string($field) && strcasecmp($field, $key) === 0) {
                return true;
            }
        }

        return false;
    }

    protected function extractRows(array $result): array
    {
        $rows = [];

        if (isset($result['id']) || isset($result['user_id'])) {
            $rows[] = $result;
        }

        foreach ($result as $value) {
            if (!is_array($value)) {
                continue;
            }

            if (isset($value['id']) || isset($value['user_id'])) {
                $rows[] = $value;
                continue;
            }

            foreach ($value as $row) {
                if (is_array($row) && (isset($row['id']) || isset($row['user_id']))) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    protected function entityNamesFromTool(string $tool): array
    {
        $tokens = preg_split('/[^a-z0-9]+/i', $tool) ?: [];
        $tokens = array_values(array_filter(
            $tokens,
            static fn (string $token): bool => $token !== '' && !in_array($token, [
                'create', 'update', 'upsert', 'delete', 'remove', 'store', 'save',
                'add', 'set', 'make', 'find', 'lookup', 'search', 'get', 'list',
            ], true)
        ));

        if ($tokens === []) {
            return ['entity'];
        }

        return array_values(array_unique(array_map(
            static fn (string $token): string => str_ends_with($token, 's') ? substr($token, 0, -1) : $token,
            $tokens
        )));
    }
}
