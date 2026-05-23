<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentRunSseStreamService
{
    public function __construct(
        private readonly AgentRunRepository $runs,
        private readonly AgentRunEventStreamService $events
    ) {}

    public function response(int|string $runId, array $options = []): StreamedResponse
    {
        if (!config('ai-agent.event_stream.sse.enabled', true)) {
            abort(404);
        }

        $run = $this->runs->findOrFail($runId);
        $this->authorize($run, $options);
        $timeout = max(1, min(120, (int) ($options['timeout'] ?? config('ai-agent.event_stream.sse.max_seconds', 30))));
        $pollMilliseconds = max(100, min(5000, (int) ($options['poll'] ?? config('ai-agent.event_stream.sse.poll_milliseconds', 500))));
        $heartbeatSeconds = max(1, (int) config('ai-agent.event_stream.sse.heartbeat_seconds', 10));
        $lastEventId = (string) ($options['last_event_id'] ?? '');

        return response()->stream(function () use ($run, $timeout, $pollMilliseconds, $heartbeatSeconds, $lastEventId): void {
            $deadline = microtime(true) + $timeout;
            $lastHeartbeat = microtime(true);
            $sent = [];

            while (microtime(true) <= $deadline) {
                $currentRun = $this->runs->find($run->id);
                if (!$currentRun instanceof AIAgentRun) {
                    break;
                }

                $events = $this->eventsAfter($this->events->fallbackEvents($currentRun), $lastEventId);
                foreach ($events as $event) {
                    $eventId = (string) ($event['id'] ?? '');
                    if ($eventId !== '' && isset($sent[$eventId])) {
                        continue;
                    }

                    echo $this->frame($event);
                    if ($eventId !== '') {
                        $sent[$eventId] = true;
                    }
                    $this->flush();
                }

                if ($currentRun->isTerminal()) {
                    break;
                }

                if ((microtime(true) - $lastHeartbeat) >= $heartbeatSeconds) {
                    echo ": heartbeat\n\n";
                    $lastHeartbeat = microtime(true);
                    $this->flush();
                }

                usleep($pollMilliseconds * 1000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function authorize(AIAgentRun $run, array $options): void
    {
        $authorizer = config('ai-agent.event_stream.sse.authorizer');
        if ($authorizer !== null) {
            $resolved = is_string($authorizer) ? app($authorizer) : $authorizer;
            $allowed = is_callable($resolved)
                ? $resolved($run, $options)
                : $resolved->authorize($run, $options);

            if ($allowed !== true) {
                abort(403);
            }

            return;
        }

        if (!(bool) config('ai-agent.event_stream.sse.authorize_owned_runs', true)) {
            return;
        }

        $runUserId = $run->user_id;
        if ($runUserId === null || $runUserId === '') {
            if (!(bool) config('ai-agent.event_stream.sse.allow_anonymous_runs', false)) {
                abort(403);
            }

            return;
        }

        $authUserId = $options['auth_user_id'] ?? null;
        if ($authUserId === null || $authUserId === '' || (string) $authUserId !== (string) $runUserId) {
            abort(403);
        }
    }

    private function eventsAfter(array $events, string $lastEventId): array
    {
        if ($lastEventId === '') {
            return $events;
        }

        $found = false;

        return array_values(array_filter($events, static function (array $event) use (&$found, $lastEventId): bool {
            if ($found) {
                return true;
            }

            if ((string) ($event['id'] ?? '') === $lastEventId) {
                $found = true;
            }

            return false;
        }));
    }

    private function frame(array $event): string
    {
        $name = (string) ($event['name'] ?? 'message');
        $id = (string) ($event['id'] ?? '');
        $data = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return "event: {$name}\n"
            . ($id !== '' ? "id: {$id}\n" : '')
            . 'data: ' . ($data !== false ? $data : '{}') . "\n\n";
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }
}
