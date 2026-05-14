<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Collectors;

use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\Services\Localization\LocaleResourceService;

class CollectorInputSchemaBuilder
{
    public function __construct(
        protected ?LocaleResourceService $localeResources = null,
    ) {
    }

    public function extractRequiredInputs(string $content, AutonomousCollectorConfig $config): ?array
    {
        $inputs = [];
        $contentLower = mb_strtolower($content);
        $yesToken = $this->locale()->lexicon('intent.confirm', default: ['yes'])[0] ?? 'yes';
        $noToken = $this->locale()->lexicon('intent.reject', default: ['no'])[0] ?? 'no';

        if ($this->looksLikeConfirmationPrompt($contentLower, $yesToken, $noToken)) {
            $inputs[] = [
                'name' => 'confirmation',
                'type' => 'confirm',
                'label' => $this->locale()->translation('ai-engine::runtime.common.confirm_label') ?: 'Confirm',
                'required' => true,
                'options' => [
                    ['value' => $yesToken, 'label' => $this->locale()->translation('ai-engine::runtime.common.yes_label') ?: 'Yes'],
                    ['value' => $noToken, 'label' => $this->locale()->translation('ai-engine::runtime.common.no_label') ?: 'No'],
                ],
            ];
        }

        foreach ($this->extractReadonlyData($content) as $key => $data) {
            $inputs[] = [
                'name' => $key,
                'type' => 'readonly',
                'label' => $data['label'],
                'value' => $data['value'],
            ];
        }

        return $inputs !== [] ? $inputs : null;
    }

    protected function looksLikeConfirmationPrompt(string $contentLower, string $yesToken, string $noToken): bool
    {
        $confirmationHint = mb_strtolower((string) $this->locale()->translation(
            'ai-engine::runtime.autonomous_collector.confirm_type_hint',
            ['yes' => $yesToken, 'no' => $noToken]
        ));
        $reviewTitle = mb_strtolower((string) $this->locale()->translation('ai-engine::runtime.autonomous_collector.confirm_review_title'));

        foreach (array_filter([trim($confirmationHint), trim($reviewTitle), "({$yesToken}/{$noToken})"]) as $pattern) {
            if (str_contains($contentLower, $pattern)) {
                return true;
            }
        }

        $yesPattern = preg_quote($yesToken, '/');
        $noPattern = preg_quote($noToken, '/');

        return (bool) preg_match("/({$yesPattern}.*{$noPattern}|{$noPattern}.*{$yesPattern})/iu", $contentLower);
    }

    protected function extractReadonlyData(string $content): array
    {
        $extractedData = [];

        if (preg_match_all('/[-•]?\s*\*\*([^*]+)\*\*:?\s*([^\n]+)/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $label = trim($match[1]);
                $value = trim((string) preg_replace('/\*\*$/', '', trim($match[2])), ' .,');
                $key = strtolower(str_replace([' ', '-'], '_', $label));

                if ($value !== '' && strlen($value) < 100) {
                    $extractedData[$key] = ['label' => $label, 'value' => $value];
                }
            }
        }

        if (preg_match_all('/^[-•]?\s*([A-Za-z][A-Za-z\s]{1,20}):\s*(.+?)(?:\s{2,}|$)/im', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $label = trim($match[1]);
                $value = trim($match[2], ' .,*');
                $key = strtolower(str_replace([' ', '-'], '_', $label));

                if (!isset($extractedData[$key]) && $value !== '' && strlen($value) < 50 && !str_contains($value, ' is ')) {
                    $extractedData[$key] = ['label' => $label, 'value' => $value];
                }
            }
        }

        return $extractedData;
    }

    protected function locale(): LocaleResourceService
    {
        if ($this->localeResources === null) {
            $this->localeResources = app(LocaleResourceService::class);
        }

        return $this->localeResources;
    }
}
