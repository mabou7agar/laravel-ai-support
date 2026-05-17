<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Collectors;

use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Localization\LocaleResourceService;

class CollectorConfirmationService
{
    public function __construct(
        protected ?LocaleResourceService $localeResources = null,
        protected ?IntentSignalService $intentSignals = null,
    ) {
    }

    public function isConfirmation(string $message): bool
    {
        return $this->locale()->isLexiconMatch(strtolower(trim($message)), 'intent.confirm');
    }

    public function isDenial(string $message): bool
    {
        return $this->locale()->isLexiconMatch(strtolower(trim($message)), 'intent.deny')
            || $this->locale()->isLexiconMatch(strtolower(trim($message)), 'intent.reject');
    }

    public function isCancellation(string $message): bool
    {
        return $this->locale()->isLexiconMatch(strtolower(trim($message)), 'intent.cancel');
    }

    public function requiresToolConfirmation(AutonomousCollectorConfig $config, string $toolName): bool
    {
        return $config->toolRequiresConfirmation($toolName);
    }

    public function isConfirmedToolCall(string $toolName, array $conversation): bool
    {
        $lastUserMessage = '';
        for ($index = count($conversation) - 1; $index >= 0; $index--) {
            if (($conversation[$index]['role'] ?? null) === 'user') {
                $lastUserMessage = strtolower(trim((string) ($conversation[$index]['content'] ?? '')));
                break;
            }
        }

        if ($lastUserMessage === '') {
            return false;
        }

        if ($this->isConfirmation($lastUserMessage)) {
            return true;
        }

        if (!$this->signals()->isAffirmative($lastUserMessage)) {
            return false;
        }

        foreach ($this->toolSubjectTokens($toolName) as $token) {
            if (str_contains($lastUserMessage, $token)) {
                return true;
            }
        }

        return false;
    }

    public function buildToolConfirmationMessage(string $toolName, array $arguments, AutonomousCollectorConfig $config): string
    {
        $description = trim((string) ($config->toolConfirmationDescription($toolName) ?? "Run {$toolName}"));
        $yesToken = $this->locale()->lexicon('intent.confirm', default: ['yes'])[0] ?? 'yes';
        $noToken = $this->locale()->lexicon('intent.reject', default: ['no'])[0] ?? 'no';
        $message = "Please confirm before I continue.\n\n";
        $message .= "**Action:** {$description}\n";

        if ($arguments !== []) {
            $message .= "**Details:**\n";
            foreach ($arguments as $key => $value) {
                if (!$this->hasDisplayValue($value) || is_array($value)) {
                    continue;
                }

                $message .= "  • **{$this->formatFieldLabel((string) $key)}**: {$this->formatScalarValue((string) $key, $value, $config)}\n";
            }
        }

        $message .= "\nType '{$yesToken}' to confirm or '{$noToken}' to change it.";

        return $message;
    }

    protected function toolSubjectTokens(string $toolName): array
    {
        $tokens = preg_split('/[^a-z0-9]+/i', strtolower($toolName)) ?: [];

        return array_values(array_filter(
            $tokens,
            static fn (string $token): bool => $token !== '' && !in_array($token, [
                'create', 'update', 'upsert', 'delete', 'remove', 'store', 'save',
                'add', 'set', 'make', 'find', 'lookup', 'search',
            ], true)
        ));
    }

    protected function hasDisplayValue(mixed $value): bool
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

    protected function formatFieldLabel(string $field): string
    {
        return ucwords(str_replace('_', ' ', $field));
    }

    protected function formatScalarValue(string $key, mixed $value, ?AutonomousCollectorConfig $config = null): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_numeric($value)) {
            $display = $this->displayConfig($config);
            $decimalFields = (array) ($display['decimal_fields'] ?? []);
            foreach ($decimalFields as $field) {
                if (is_string($field) && strcasecmp($field, $key) === 0) {
                    $decimals = (int) ($display['decimal_places'] ?? 2);

                    return number_format((float) $value, max(0, $decimals), '.', '');
                }
            }

            return (string) $value;
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    protected function displayConfig(?AutonomousCollectorConfig $config): array
    {
        $display = $config?->context['collector_display'] ?? [];

        return is_array($display) ? $display : [];
    }

    protected function locale(): LocaleResourceService
    {
        if ($this->localeResources === null) {
            $this->localeResources = app(LocaleResourceService::class);
        }

        return $this->localeResources;
    }

    protected function signals(): IntentSignalService
    {
        return $this->intentSignals ??= app(IntentSignalService::class);
    }
}
