<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\File;
use LaravelAIEngine\Contracts\ActionDefinitionProvider;
use LaravelAIEngine\Contracts\AgentSkillProvider;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use Throwable;

class AgentManifestService
{
    protected ?array $manifest = null;

    public function modelConfigs(): array
    {
        $manifest = $this->manifest();
        $configs = $manifest['model_configs'] ?? [];
        if (!is_array($configs)) {
            return [];
        }

        $classes = [];
        foreach ($configs as $className) {
            if (!is_string($className) || trim($className) === '') {
                continue;
            }
            if (!class_exists($className)) {
                continue;
            }
            $classes[] = $className;
        }

        return array_values(array_unique($classes));
    }

    /**
     * @return array<string, string>
     */
    public function tools(): array
    {
        $manifest = $this->manifest();
        $tools = $manifest['tools'] ?? [];
        if (!is_array($tools)) {
            return [];
        }

        $resolved = [];
        foreach ($tools as $name => $className) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            if (!is_string($className) || trim($className) === '') {
                continue;
            }
            if (!class_exists($className)) {
                continue;
            }
            $resolved[$name] = $className;
        }

        foreach ($this->discoverToolClasses() as $name => $className) {
            $resolved[$name] ??= $className;
        }

        return $resolved;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function skills(): array
    {
        $manifest = $this->manifest();
        $skills = $manifest['skills'] ?? [];
        if (!is_array($skills)) {
            return [];
        }

        $resolved = [];
        foreach ($skills as $id => $definition) {
            if (!is_string($id) || trim($id) === '') {
                continue;
            }

            if (!is_array($definition)) {
                continue;
            }

            $resolved[$id] = $definition;
        }

        return $resolved;
    }

    /**
     * @return array<string, string>
     */
    public function skillProviders(): array
    {
        $manifest = $this->manifest();
        $providers = $manifest['skill_providers'] ?? [];
        if (!is_array($providers)) {
            return [];
        }

        $resolved = [];
        foreach ($providers as $name => $className) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            if (!is_string($className) || trim($className) === '') {
                continue;
            }
            if (!class_exists($className)) {
                continue;
            }
            $resolved[$name] = $className;
        }

        foreach ($this->discoverProviderClasses(AgentSkillProvider::class, [app_path('AI/Skills')]) as $name => $className) {
            $resolved[$name] ??= $className;
        }

        return $resolved;
    }

    /**
     * @return array<string, string>
     */
    public function actionProviders(): array
    {
        $manifest = $this->manifest();
        $providers = $manifest['action_providers'] ?? [];
        $resolved = [];

        if (is_array($providers)) {
            foreach ($providers as $name => $className) {
                if (is_int($name)) {
                    $name = (string) $className;
                }

                if (!is_string($name) || trim($name) === '') {
                    continue;
                }

                if (!is_string($className) || trim($className) === '' || !class_exists($className)) {
                    continue;
                }

                if (!is_subclass_of($className, ActionDefinitionProvider::class)) {
                    continue;
                }

                $resolved[$name] = $className;
            }
        }

        foreach ($this->discoverProviderClasses(ActionDefinitionProvider::class, [app_path('AI/Actions'), app_path('AI/Skills')]) as $name => $className) {
            $resolved[$name] ??= $className;
        }

        return $resolved;
    }

    /**
     * @return array<string, array{class:string,description:string,priority:int}>
     */
    public function collectors(): array
    {
        $manifest = $this->manifest();
        $collectors = $manifest['collectors'] ?? [];
        if (!is_array($collectors)) {
            return [];
        }

        $resolved = [];
        foreach ($collectors as $name => $definition) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }

            if (is_string($definition)) {
                $className = $definition;
                $description = '';
                $priority = 0;
            } elseif (is_array($definition)) {
                $className = (string) ($definition['class'] ?? '');
                $description = (string) ($definition['description'] ?? '');
                $priority = (int) ($definition['priority'] ?? 0);
            } else {
                continue;
            }

            if ($className === '' || !class_exists($className)) {
                continue;
            }

            $resolved[$name] = [
                'class' => $className,
                'description' => $description,
                'priority' => $priority,
            ];
        }

        return $resolved;
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        $manifest = $this->manifest();
        $filters = $manifest['filters'] ?? [];
        if (!is_array($filters)) {
            return [];
        }

        $resolved = [];
        foreach ($filters as $name => $className) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            if (!is_string($className) || trim($className) === '') {
                continue;
            }
            if (!class_exists($className)) {
                continue;
            }
            $resolved[$name] = $className;
        }

        return $resolved;
    }

    public function fallbackDiscoveryEnabled(): bool
    {
        return (bool) config('ai-agent.manifest.fallback_discovery', true);
    }

    public function manifestPath(): string
    {
        $configured = config('ai-agent.manifest.path');
        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return app_path('AI/agent-manifest.php');
    }

    public function refresh(): void
    {
        $this->manifest = null;
    }

    protected function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $path = $this->manifestPath();
        if (!is_file($path)) {
            $this->manifest = [];
            return $this->manifest;
        }

        try {
            $loaded = require $path;
            $this->manifest = is_array($loaded) ? $loaded : [];
        } catch (\Throwable) {
            $this->manifest = [];
        }

        return $this->manifest;
    }

    /**
     * @return array<string, string>
     */
    protected function discoverToolClasses(): array
    {
        if (!$this->fallbackDiscoveryEnabled()) {
            return [];
        }

        $resolved = [];
        foreach ($this->classesInDirectories([app_path('AI/Tools')]) as $className) {
            if (!is_subclass_of($className, AgentTool::class)) {
                continue;
            }

            try {
                /** @var AgentTool $tool */
                $tool = app($className);
                $name = trim($tool->getName());
                if ($name !== '') {
                    $resolved[$name] = $className;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $resolved;
    }

    /**
     * @param class-string $contract
     * @param array<int, string> $directories
     * @return array<string, string>
     */
    protected function discoverProviderClasses(string $contract, array $directories): array
    {
        if (!$this->fallbackDiscoveryEnabled()) {
            return [];
        }

        $resolved = [];
        foreach ($this->classesInDirectories($directories) as $className) {
            if (!is_subclass_of($className, $contract)) {
                continue;
            }

            $resolved[$className] = $className;
        }

        return $resolved;
    }

    /**
     * @param array<int, string> $directories
     * @return array<int, class-string>
     */
    protected function classesInDirectories(array $directories): array
    {
        $classes = [];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            foreach (File::allFiles($directory) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $className = $this->classNameFromFile($file->getPathname());
                if ($className !== null && class_exists($className)) {
                    $classes[] = $className;
                }
            }
        }

        return array_values(array_unique($classes));
    }

    protected function classNameFromFile(string $path): ?string
    {
        $contents = @file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            return null;
        }

        $namespace = '';
        if (preg_match('/^namespace\s+([^;]+);/m', $contents, $matches) === 1) {
            $namespace = trim($matches[1]);
        }

        if (preg_match('/^(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents, $matches) !== 1) {
            return null;
        }

        return $namespace !== '' ? $namespace . '\\' . $matches[1] : $matches[1];
    }
}
