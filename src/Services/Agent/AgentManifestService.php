<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

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
}
