<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK;

class TraceRecorderService
{
    protected array $spans = [];

    public function start(string $name, array $metadata = []): string
    {
        $id = 'trace_' . bin2hex(random_bytes(8));

        $this->spans[$id] = [
            'id' => $id,
            'name' => $name,
            'metadata' => $metadata,
            'started_at' => microtime(true),
            'ended_at' => null,
            'duration_ms' => null,
            'status' => 'running',
        ];

        return $id;
    }

    public function end(string $id, string $status = 'ok', array $metadata = []): array
    {
        if (!isset($this->spans[$id])) {
            throw new \InvalidArgumentException("Trace span [{$id}] does not exist.");
        }

        $endedAt = microtime(true);
        $this->spans[$id]['ended_at'] = $endedAt;
        $this->spans[$id]['duration_ms'] = (int) round(($endedAt - $this->spans[$id]['started_at']) * 1000);
        $this->spans[$id]['status'] = $status;
        $this->spans[$id]['metadata'] = array_merge($this->spans[$id]['metadata'], $metadata);

        return $this->spans[$id];
    }

    public function record(string $name, array $metadata = [], string $status = 'ok'): array
    {
        return $this->end($this->start($name, $metadata), $status);
    }

    public function all(): array
    {
        return array_values($this->spans);
    }

    public function flush(): array
    {
        $spans = $this->all();
        $this->spans = [];

        return $spans;
    }
}
