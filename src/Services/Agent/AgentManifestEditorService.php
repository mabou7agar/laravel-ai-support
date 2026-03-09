<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\File;

class AgentManifestEditorService
{
    public function __construct(protected AgentManifestService $manifestService)
    {
    }

    /**
     * @return array{model_configs:array<int,string>,collectors:array<string,string>,tools:array<string,string>,filters:array<string,string>}
     */
    public function read(): array
    {
        $path = $this->manifestPath();

        $defaults = [
            'model_configs' => [],
            'collectors' => [],
            'tools' => [],
            'filters' => [],
        ];

        if (!is_file($path)) {
            return $defaults;
        }

        try {
            $loaded = require $path;
            if (!is_array($loaded)) {
                return $defaults;
            }

            $manifest = array_merge($defaults, $loaded);

            $manifest['model_configs'] = array_values(array_filter(array_map(
                static fn ($class): string => trim((string) $class),
                (array) ($manifest['model_configs'] ?? [])
            )));

            foreach (['collectors', 'tools', 'filters'] as $section) {
                $normalized = [];
                foreach ((array) ($manifest[$section] ?? []) as $key => $class) {
                    $entryKey = trim((string) $key);
                    $entryClass = trim((string) $class);
                    if ($entryKey === '' || $entryClass === '') {
                        continue;
                    }
                    $normalized[$entryKey] = $entryClass;
                }
                $manifest[$section] = $normalized;
            }

            return $manifest;
        } catch (\Throwable) {
            return $defaults;
        }
    }

    public function putModelConfig(string $className): bool
    {
        $className = $this->normalizeClass($className);
        if ($className === '') {
            return false;
        }

        $manifest = $this->read();
        if (in_array($className, $manifest['model_configs'], true)) {
            return false;
        }

        $manifest['model_configs'][] = $className;
        $manifest['model_configs'] = array_values(array_unique($manifest['model_configs']));

        $this->write($manifest);

        return true;
    }

    public function replaceModelConfig(string $oldClassName, string $newClassName): bool
    {
        $oldClassName = $this->normalizeClass($oldClassName);
        $newClassName = $this->normalizeClass($newClassName);

        if ($newClassName === '') {
            return false;
        }

        $manifest = $this->read();
        $current = $manifest['model_configs'];

        $updated = array_values(array_filter(
            $current,
            static fn (string $entry): bool => $oldClassName === '' || $entry !== $oldClassName
        ));

        if (!in_array($newClassName, $updated, true)) {
            $updated[] = $newClassName;
        }

        $updated = array_values(array_unique($updated));

        if ($updated === $current) {
            return false;
        }

        $manifest['model_configs'] = $updated;
        $this->write($manifest);

        return true;
    }

    public function putMappedEntry(string $section, string $key, string $className): bool
    {
        if (!in_array($section, ['collectors', 'tools', 'filters'], true)) {
            return false;
        }

        $normalizedKey = trim($key);
        $normalizedClass = $this->normalizeClass($className);

        if ($normalizedKey === '' || $normalizedClass === '') {
            return false;
        }

        $manifest = $this->read();
        $current = (string) ($manifest[$section][$normalizedKey] ?? '');

        if ($current === $normalizedClass) {
            return false;
        }

        $manifest[$section][$normalizedKey] = $normalizedClass;
        ksort($manifest[$section]);

        $this->write($manifest);

        return true;
    }

    public function replaceMappedEntry(string $section, string $oldKey, string $newKey, string $className): bool
    {
        if (!in_array($section, ['collectors', 'tools', 'filters'], true)) {
            return false;
        }

        $normalizedOldKey = trim($oldKey);
        $normalizedNewKey = trim($newKey);
        $normalizedClass = $this->normalizeClass($className);

        if ($normalizedNewKey === '' || $normalizedClass === '') {
            return false;
        }

        $manifest = $this->read();
        $current = $manifest[$section];
        $updated = $current;

        if ($normalizedOldKey !== '' && $normalizedOldKey !== $normalizedNewKey) {
            unset($updated[$normalizedOldKey]);
        }

        $updated[$normalizedNewKey] = $normalizedClass;
        ksort($updated);

        if ($updated === $current) {
            return false;
        }

        $manifest[$section] = $updated;
        $this->write($manifest);

        return true;
    }

    public function removeModelConfig(string $className): bool
    {
        $className = $this->normalizeClass($className);
        if ($className === '') {
            return false;
        }

        $manifest = $this->read();
        $before = count($manifest['model_configs']);
        $manifest['model_configs'] = array_values(array_filter(
            $manifest['model_configs'],
            static fn (string $entry): bool => $entry !== $className
        ));

        if ($before === count($manifest['model_configs'])) {
            return false;
        }

        $this->write($manifest);

        return true;
    }

    public function removeMappedEntry(string $section, string $key): bool
    {
        if (!in_array($section, ['collectors', 'tools', 'filters'], true)) {
            return false;
        }

        $normalizedKey = trim($key);
        if ($normalizedKey === '') {
            return false;
        }

        $manifest = $this->read();
        if (!array_key_exists($normalizedKey, $manifest[$section])) {
            return false;
        }

        unset($manifest[$section][$normalizedKey]);
        $this->write($manifest);

        return true;
    }

    public function manifestPath(): string
    {
        $path = $this->manifestService->manifestPath();

        if ($path === '') {
            return app_path('AI/agent-manifest.php');
        }

        if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @param array<mixed> $manifest
     */
    public function replaceAll(array $manifest): void
    {
        $normalized = [
            'model_configs' => [],
            'collectors' => [],
            'tools' => [],
            'filters' => [],
        ];

        foreach ((array) ($manifest['model_configs'] ?? []) as $className) {
            $normalizedClass = $this->normalizeClass((string) $className);
            if ($normalizedClass !== '') {
                $normalized['model_configs'][] = $normalizedClass;
            }
        }
        $normalized['model_configs'] = array_values(array_unique($normalized['model_configs']));

        foreach (['collectors', 'tools', 'filters'] as $section) {
            foreach ((array) ($manifest[$section] ?? []) as $key => $className) {
                $normalizedKey = trim((string) $key);
                $normalizedClass = $this->normalizeClass((string) $className);
                if ($normalizedKey === '' || $normalizedClass === '') {
                    continue;
                }
                $normalized[$section][$normalizedKey] = $normalizedClass;
            }
            ksort($normalized[$section]);
        }

        $this->write($normalized);
    }

    /**
     * @param array{model_configs:array<int,string>,collectors:array<string,string>,tools:array<string,string>,filters:array<string,string>} $manifest
     */
    protected function write(array $manifest): void
    {
        $path = $this->manifestPath();
        File::ensureDirectoryExists(dirname($path));

        $content = "<?php\n\nreturn " . var_export([
            'model_configs' => array_values($manifest['model_configs']),
            'collectors' => $manifest['collectors'],
            'tools' => $manifest['tools'],
            'filters' => $manifest['filters'],
        ], true) . ";\n";

        File::put($path, $content);
        $this->manifestService->refresh();
    }

    protected function normalizeClass(string $className): string
    {
        $className = trim($className);
        if ($className === '') {
            return '';
        }

        $className = preg_replace('/::class$/', '', $className) ?? $className;

        return ltrim($className, '\\');
    }
}
