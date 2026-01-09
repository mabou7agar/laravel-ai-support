<?php

namespace LaravelAIEngine\Services\Agent\Tools;

use Illuminate\Support\Facades\Log;

class ToolRegistry
{
    protected array $tools = [];

    public function register(string $name, AgentTool $tool): void
    {
        $this->tools[$name] = $tool;
        
        Log::channel('ai-engine')->debug('Tool registered', [
            'name' => $name,
            'class' => get_class($tool),
        ]);
    }

    public function get(string $name): ?AgentTool
    {
        return $this->tools[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function all(): array
    {
        return $this->tools;
    }

    public function getToolDefinitions(): array
    {
        return array_map(fn($tool) => $tool->toArray(), $this->tools);
    }

    public function discoverFromConfig(): void
    {
        $toolClasses = config('ai-agent.tools', []);
        
        foreach ($toolClasses as $name => $class) {
            if (class_exists($class)) {
                try {
                    $tool = app($class);
                    $this->register($name, $tool);
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->warning('Failed to register tool', [
                        'name' => $name,
                        'class' => $class,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
