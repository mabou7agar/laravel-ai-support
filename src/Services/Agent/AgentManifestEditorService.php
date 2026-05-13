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
     * @return array{model_configs:array<int,string>,collectors:array<string,string>,tools:array<string,string>,filters:array<string,string>,skill_providers:array<string,string>,skills:array<string,array<string,mixed>>}
     */
    public function read(): array
    {
        $path = $this->manifestPath();

        $defaults = [
            'model_configs' => [],
            'collectors' => [],
            'tools' => [],
            'filters' => [],
            'skill_providers' => [],
            'skills' => [],
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

            foreach (['collectors', 'tools', 'filters', 'skill_providers'] as $section) {
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

            $manifest['skills'] = $this->normalizeSkillDefinitions((array) ($manifest['skills'] ?? []));

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
        if (!in_array($section, ['collectors', 'tools', 'filters', 'skill_providers'], true)) {
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
        if (!in_array($section, ['collectors', 'tools', 'filters', 'skill_providers'], true)) {
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
        if (!in_array($section, ['collectors', 'tools', 'filters', 'skill_providers'], true)) {
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
     * @param array<string, array<string, mixed>> $skills
     */
    public function putSkillDefinitions(array $skills): int
    {
        $manifest = $this->read();
        $written = 0;

        foreach ($skills as $id => $definition) {
            $normalizedId = trim((string) ($definition['id'] ?? $id));
            if ($normalizedId === '') {
                continue;
            }

            $definition['id'] = $normalizedId;
            $normalized = $this->normalizeSkillDefinitions([$normalizedId => $definition]);
            if (!isset($normalized[$normalizedId])) {
                continue;
            }

            if (($manifest['skills'][$normalizedId] ?? null) !== $normalized[$normalizedId]) {
                $manifest['skills'][$normalizedId] = $normalized[$normalizedId];
                $written++;
            }
        }

        if ($written > 0) {
            ksort($manifest['skills']);
            $this->write($manifest);
        }

        return $written;
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
            'skill_providers' => [],
            'skills' => [],
        ];

        foreach ((array) ($manifest['model_configs'] ?? []) as $className) {
            $normalizedClass = $this->normalizeClass((string) $className);
            if ($normalizedClass !== '') {
                $normalized['model_configs'][] = $normalizedClass;
            }
        }
        $normalized['model_configs'] = array_values(array_unique($normalized['model_configs']));

        foreach (['collectors', 'tools', 'filters', 'skill_providers'] as $section) {
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

        $normalized['skills'] = $this->normalizeSkillDefinitions((array) ($manifest['skills'] ?? []));

        $this->write($normalized);
    }

    /**
     * @param array{model_configs:array<int,string>,collectors:array<string,string>,tools:array<string,string>,filters:array<string,string>,skill_providers?:array<string,string>,skills?:array<string,array<string,mixed>>} $manifest
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
            'skill_providers' => $manifest['skill_providers'] ?? [],
            'skills' => $manifest['skills'] ?? [],
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

    /**
     * @param array<string|int, mixed> $skills
     * @return array<string, array<string, mixed>>
     */
    protected function normalizeSkillDefinitions(array $skills): array
    {
        $normalized = [];

        foreach ($skills as $key => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $id = trim((string) ($definition['id'] ?? (is_string($key) ? $key : '')));
            $name = trim((string) ($definition['name'] ?? ''));
            $description = trim((string) ($definition['description'] ?? ''));

            if ($id === '' || $name === '' || $description === '') {
                continue;
            }

            $definition['id'] = $id;
            $definition['name'] = $name;
            $definition['description'] = $description;
            $definition['triggers'] = $this->normalizeStringList($definition['triggers'] ?? []);
            $definition['required_data'] = $this->normalizeStringList($definition['required_data'] ?? $definition['requiredData'] ?? []);
            $definition['tools'] = $this->normalizeStringList($definition['tools'] ?? []);
            $definition['actions'] = $this->normalizeStringList($definition['actions'] ?? []);
            $definition['workflows'] = $this->normalizeStringList($definition['workflows'] ?? []);
            $definition['capabilities'] = $this->normalizeStringList($definition['capabilities'] ?? []);
            $definition['requires_confirmation'] = (bool) ($definition['requires_confirmation'] ?? $definition['requiresConfirmation'] ?? true);
            $definition['enabled'] = (bool) ($definition['enabled'] ?? false);
            $definition['metadata'] = (array) ($definition['metadata'] ?? []);

            unset($definition['requiredData'], $definition['requiresConfirmation']);

            $normalized[$id] = $definition;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    protected function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value
        )));
    }
}
