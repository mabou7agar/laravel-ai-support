<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Localization;

use Illuminate\Support\Facades\Lang;

class LocaleResourceService
{
    protected array $templateCache = [];

    public function resolveLocale(?string $locale = null): string
    {
        $normalized = $this->normalizeLocale($locale);
        $supported = $this->supportedLocales();
        $fallback = $this->configuredFallbackLocale();

        if ($normalized !== null) {
            if ($supported === [] || in_array($normalized, $supported, true)) {
                return $normalized;
            }

            $base = $this->baseLocale($normalized);
            if ($base !== null && ($supported === [] || in_array($base, $supported, true))) {
                return $base;
            }
        }

        $appLocale = $this->normalizeLocale((string) app()->getLocale());
        if ($appLocale !== null && ($supported === [] || in_array($appLocale, $supported, true))) {
            return $appLocale;
        }

        return $supported[0] ?? $fallback;
    }

    public function languageName(?string $locale = null): string
    {
        $resolved = $this->normalizeLocale($locale) ?? $this->resolveLocale();
        $name = $this->translation('ai-engine::lexicon.language.name', locale: $resolved);

        if ($name === '') {
            $base = $this->baseLocale($resolved);
            if ($base !== null && $base !== $resolved) {
                $name = $this->translation('ai-engine::lexicon.language.name', locale: $base);
            }
        }

        return $name !== '' ? $name : strtoupper($resolved);
    }

    public function lexicon(string $key, ?string $locale = null, array $default = []): array
    {
        $resolved = $this->resolveLocale($locale);
        $translated = Lang::get("ai-engine::lexicon.{$key}", [], $resolved);

        if (!is_array($translated)) {
            $fallback = Lang::get("ai-engine::lexicon.{$key}", [], $this->configuredFallbackLocale());
            if (!is_array($fallback)) {
                return $default;
            }
            $translated = $fallback;
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $value): string => mb_strtolower(trim((string) $value)),
                $translated
            ),
            static fn (string $value): bool => $value !== ''
        ));
    }

    public function translation(
        string $key,
        array $replace = [],
        ?string $locale = null,
        ?string $fallbackKey = null
    ): string {
        $resolved = $this->resolveLocale($locale);
        $translated = Lang::get($key, $replace, $resolved);
        if (is_string($translated) && $translated !== $key) {
            return $translated;
        }

        if ($fallbackKey !== null) {
            $fallback = Lang::get($fallbackKey, $replace, $resolved);
            if (is_string($fallback) && $fallback !== $fallbackKey) {
                return $fallback;
            }
        }

        return '';
    }

    public function renderPromptTemplate(string $template, array $replace = [], ?string $locale = null): string
    {
        $resolved = $this->resolveLocale($locale);
        $content = $this->loadPromptTemplate($template, $resolved);
        $fallbackLocale = $this->configuredFallbackLocale();

        if ($content === null && $resolved !== $fallbackLocale) {
            $content = $this->loadPromptTemplate($template, $fallbackLocale);
        }

        if ($content === null) {
            foreach ($this->supportedLocales() as $supportedLocale) {
                if ($supportedLocale === $resolved || $supportedLocale === $fallbackLocale) {
                    continue;
                }
                $content = $this->loadPromptTemplate($template, $supportedLocale);
                if ($content !== null) {
                    break;
                }
            }
        }

        if ($content === null) {
            return '';
        }

        $replacements = [];
        foreach ($replace as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $replacements['{{' . $key . '}}'] = (string) $value;
        }

        return strtr($content, $replacements);
    }

    public function isLexiconMatch(string $message, string $key, ?string $locale = null): bool
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return false;
        }

        foreach ($this->candidateLocales($locale) as $candidate) {
            if (in_array($normalized, $this->lexicon($key, $candidate), true)) {
                return true;
            }
        }

        return false;
    }

    public function startsWithLexicon(string $message, string $key, ?string $locale = null): bool
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return false;
        }

        foreach ($this->candidateLocales($locale) as $candidate) {
            foreach ($this->lexicon($key, $candidate) as $keyword) {
                if ($normalized === $keyword || str_starts_with($normalized, $keyword . ' ')) {
                    return true;
                }
            }
        }

        return false;
    }

    public function containsLexicon(string $message, string $key, ?string $locale = null): bool
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return false;
        }

        foreach ($this->candidateLocales($locale) as $candidate) {
            foreach ($this->lexicon($key, $candidate) as $keyword) {
                if (mb_strlen($keyword) < 4) {
                    continue;
                }

                if (str_contains($normalized, $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function responseBoolean(string $response, ?string $locale = null): ?bool
    {
        $normalized = mb_strtolower(trim($response));
        if ($normalized === '') {
            return null;
        }

        foreach ($this->candidateLocales($locale) as $candidate) {
            foreach ($this->lexicon('response.affirmative', $candidate, ['yes']) as $token) {
                if ($normalized === $token || str_starts_with($normalized, $token . ' ')) {
                    return true;
                }
            }

            foreach ($this->lexicon('response.negative', $candidate, ['no']) as $token) {
                if ($normalized === $token || str_starts_with($normalized, $token . ' ')) {
                    return false;
                }
            }
        }

        return null;
    }

    protected function loadPromptTemplate(string $template, string $locale): ?string
    {
        $cacheKey = "{$locale}:{$template}";
        if (array_key_exists($cacheKey, $this->templateCache)) {
            return $this->templateCache[$cacheKey];
        }

        $path = $this->promptTemplatePath($template, $locale);
        if (!is_file($path)) {
            $this->templateCache[$cacheKey] = null;
            return null;
        }

        $content = file_get_contents($path);
        $this->templateCache[$cacheKey] = $content === false ? null : $content;

        return $this->templateCache[$cacheKey];
    }

    protected function promptTemplatePath(string $template, string $locale): string
    {
        $basePath = config('ai-engine.prompt_templates.path');
        if (!is_string($basePath) || trim($basePath) === '') {
            $basePath = dirname(__DIR__, 4) . '/resources/prompts';
        }

        $template = ltrim($template, '/');

        return rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $template . '.txt';
    }

    protected function supportedLocales(): array
    {
        $supported = config('ai-engine.localization.supported_locales', []);
        if (!is_array($supported)) {
            return [];
        }

        $normalized = [];
        foreach ($supported as $locale) {
            $candidate = $this->normalizeLocale((string) $locale);
            if ($candidate !== null && !in_array($candidate, $normalized, true)) {
                $normalized[] = $candidate;
            }
        }

        return $normalized;
    }

    protected function candidateLocales(?string $locale): array
    {
        $candidates = [];
        $resolved = $this->resolveLocale($locale);
        $candidates[] = $resolved;

        if ($locale === null) {
            foreach ($this->supportedLocales() as $supportedLocale) {
                if (!in_array($supportedLocale, $candidates, true)) {
                    $candidates[] = $supportedLocale;
                }
            }
        }

        return $candidates;
    }

    protected function configuredFallbackLocale(): string
    {
        $supported = $this->supportedLocales();
        $configured = config('ai-engine.localization.fallback_locale')
            ?: config('app.fallback_locale')
            ?: app()->getLocale();

        $normalized = $this->normalizeLocale(is_string($configured) ? $configured : null);
        if ($normalized !== null) {
            if ($supported === [] || in_array($normalized, $supported, true)) {
                return $normalized;
            }

            $base = $this->baseLocale($normalized);
            if ($base !== null && ($supported === [] || in_array($base, $supported, true))) {
                return $base;
            }
        }

        $appLocale = $this->normalizeLocale((string) app()->getLocale());
        if ($appLocale !== null && ($supported === [] || in_array($appLocale, $supported, true))) {
            return $appLocale;
        }

        return $supported[0] ?? 'en';
    }

    protected function normalizeLocale(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }

        $normalized = strtolower(str_replace('_', '-', trim($locale)));
        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }

    protected function baseLocale(string $locale): ?string
    {
        $parts = explode('-', $locale);
        $base = $parts[0] ?? null;

        return ($base !== null && $base !== '') ? $base : null;
    }
}
