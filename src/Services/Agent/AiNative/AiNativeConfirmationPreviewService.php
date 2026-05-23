<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

class AiNativeConfirmationPreviewService
{
    /**
     * @param array<string, mixed> $arguments
     * @return array{arguments: array<string, mixed>, summary: array<string, mixed>, result: ActionResult|null}
     */
    public function preview(AgentTool $tool, array $arguments, UnifiedActionContext $context): array
    {
        $result = $tool->previewConfirmation($arguments, $context);
        if (!$result instanceof ActionResult || !$result->success || $result->requiresUserInput()) {
            return [
                'arguments' => $arguments,
                'summary' => $this->withComputedTotals($arguments),
                'result' => $result,
            ];
        }

        $data = is_array($result->data) ? $result->data : [];
        $draft = is_array($data['draft'] ?? null) ? $data['draft'] : [];
        $payload = is_array($draft['payload'] ?? null)
            ? (array) $draft['payload']
            : (is_array($data['payload'] ?? null) ? (array) $data['payload'] : $arguments);
        $summary = is_array($draft['summary'] ?? null)
            ? (array) $draft['summary']
            : (is_array($data['summary'] ?? null) ? (array) $data['summary'] : $payload);

        return [
            'arguments' => $payload,
            'summary' => $summary,
            'result' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function withComputedTotals(array $summary): array
    {
        if (!(bool) config('ai-agent.ai_native.confirmation_summary.computed_totals.enabled', true)) {
            return $summary;
        }

        foreach ($summary as $key => $value) {
            if (!is_array($value) || !$this->isList($value)) {
                continue;
            }

            $computed = $this->computedListTotals($value);
            if ($computed === null) {
                continue;
            }

            $summary[$key] = $computed['items'];
            $subtotalField = $this->configuredField('subtotal_field', 'subtotal');
            $totalField = $this->configuredField('total_field', 'total');

            if (!array_key_exists($subtotalField, $summary)) {
                $summary[$subtotalField] = $computed['total'];
            }

            if (!array_key_exists($totalField, $summary)) {
                $summary[$totalField] = $computed['total'];
            }
        }

        return $summary;
    }

    /**
     * @param array<int, mixed> $items
     * @return array{items: array<int, mixed>, total: float|int}|null
     */
    private function computedListTotals(array $items): ?array
    {
        $lineTotalField = $this->configuredField('line_total_field', 'line_total');
        $total = 0.0;
        $computedItems = [];
        $hasComputedLine = false;

        foreach ($items as $item) {
            if (!is_array($item)) {
                return null;
            }

            $lineTotal = $this->lineTotal($item);
            if ($lineTotal === null) {
                $computedItems[] = $item;
                continue;
            }

            $hasComputedLine = true;
            $total += $lineTotal;
            if (!array_key_exists($lineTotalField, $item)) {
                $item[$lineTotalField] = $this->normalizeNumber($lineTotal);
            }

            $computedItems[] = $item;
        }

        if (!$hasComputedLine) {
            return null;
        }

        return [
            'items' => $computedItems,
            'total' => $this->normalizeNumber($total),
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function lineTotal(array $item): ?float
    {
        $quantity = $this->firstNumericValue($item, (array) config(
            'ai-agent.ai_native.confirmation_summary.computed_totals.quantity_fields',
            ['quantity', 'qty']
        ));
        $unitAmount = $this->firstNumericValue($item, (array) config(
            'ai-agent.ai_native.confirmation_summary.computed_totals.unit_amount_fields',
            ['unit_price', 'price', 'rate', 'amount']
        ));

        if ($quantity === null || $unitAmount === null) {
            return null;
        }

        return $quantity * $unitAmount;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, mixed> $fields
     */
    private function firstNumericValue(array $values, array $fields): ?float
    {
        foreach ($fields as $field) {
            if (!is_string($field) || $field === '' || !array_key_exists($field, $values) || !is_numeric($values[$field])) {
                continue;
            }

            return (float) $values[$field];
        }

        return null;
    }

    private function configuredField(string $key, string $default): string
    {
        $value = config('ai-agent.ai_native.confirmation_summary.computed_totals.'.$key, $default);

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function normalizeNumber(float $value): float|int
    {
        return fmod($value, 1.0) === 0.0 ? (int) $value : round($value, 6);
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function isList(array $value): bool
    {
        return array_is_list($value);
    }
}
