<?php

namespace LaravelAIEngine\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetRequestLocaleMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('ai-engine.localization.enabled', true)) {
            return $next($request);
        }

        $locale = $this->resolveLocale($request);

        if ($locale !== null && $locale !== '') {
            app()->setLocale($locale);
            $request->attributes->set('ai_engine_locale', $locale);
        }

        return $next($request);
    }

    protected function resolveLocale(Request $request): ?string
    {
        $supported = $this->normalizedSupportedLocales();

        $queryParameter = (string) config('ai-engine.localization.query_parameter', 'locale');
        if ($queryParameter !== '') {
            $fromQuery = $this->selectSupportedLocale($request->query($queryParameter), $supported);
            if ($fromQuery !== null) {
                return $fromQuery;
            }
        }

        $headerName = (string) config('ai-engine.localization.header', 'X-Locale');
        if ($headerName !== '') {
            $fromHeader = $this->selectSupportedLocale($request->header($headerName), $supported);
            if ($fromHeader !== null) {
                return $fromHeader;
            }
        }

        if (config('ai-engine.localization.detect_from_user', true)) {
            $fromUser = $this->resolveFromUser($supported);
            if ($fromUser !== null) {
                return $fromUser;
            }
        }

        if (config('ai-engine.localization.detect_from_message', true)) {
            $fromMessage = $this->resolveFromMessage($request, $supported);
            if ($fromMessage !== null) {
                return $fromMessage;
            }
        }

        if (config('ai-engine.localization.detect_from_accept_language', true)) {
            foreach ($request->getLanguages() as $acceptedLocale) {
                $selected = $this->selectSupportedLocale($acceptedLocale, $supported);
                if ($selected !== null) {
                    return $selected;
                }
            }
        }

        $fallback = config('ai-engine.localization.fallback_locale') ?: config('app.fallback_locale');

        return $this->selectSupportedLocale($fallback, $supported)
            ?? $this->selectSupportedLocale(config('app.locale'), $supported)
            ?? ($supported[0] ?? null);
    }

    protected function resolveFromUser(array $supported): ?string
    {
        $user = auth()->user();
        if (!$user) {
            return null;
        }

        $keys = config('ai-engine.localization.user_locale_keys', ['locale', 'language']);
        if (!is_array($keys)) {
            $keys = ['locale', 'language'];
        }

        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $value = data_get($user, $key);
            $selected = $this->selectSupportedLocale($value, $supported);
            if ($selected !== null) {
                return $selected;
            }
        }

        return null;
    }

    protected function resolveFromMessage(Request $request, array $supported): ?string
    {
        $message = $request->input('message');
        if (!is_string($message) || trim($message) === '') {
            return null;
        }

        $scriptDetection = config('ai-engine.localization.script_detection', []);
        if (!is_array($scriptDetection)) {
            return null;
        }

        foreach ($scriptDetection as $locale => $pattern) {
            if (!is_string($locale) || !is_string($pattern) || $pattern === '') {
                continue;
            }

            if (@preg_match($pattern, $message)) {
                $selected = $this->selectSupportedLocale($locale, $supported);
                if ($selected !== null) {
                    return $selected;
                }
            }
        }

        return null;
    }

    protected function normalizedSupportedLocales(): array
    {
        $configured = config('ai-engine.localization.supported_locales', []);

        if (!is_array($configured)) {
            return [];
        }

        $normalized = [];
        foreach ($configured as $locale) {
            $parsed = $this->normalizeLocale($locale);
            if ($parsed !== null && !in_array($parsed, $normalized, true)) {
                $normalized[] = $parsed;
            }
        }

        return $normalized;
    }

    protected function selectSupportedLocale(mixed $candidate, array $supported): ?string
    {
        $normalized = $this->normalizeLocale($candidate);
        if ($normalized === null) {
            return null;
        }

        if ($supported === []) {
            return $normalized;
        }

        if (in_array($normalized, $supported, true)) {
            return $normalized;
        }

        $base = $this->baseLocale($normalized);
        if ($base !== null && in_array($base, $supported, true)) {
            return $base;
        }

        if ($base !== null) {
            foreach ($supported as $supportedLocale) {
                if ($this->baseLocale($supportedLocale) === $base) {
                    return $supportedLocale;
                }
            }
        }

        return null;
    }

    protected function normalizeLocale(mixed $locale): ?string
    {
        if (!is_string($locale) && !is_numeric($locale)) {
            return null;
        }

        $normalized = strtolower(str_replace('_', '-', trim((string) $locale)));
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
