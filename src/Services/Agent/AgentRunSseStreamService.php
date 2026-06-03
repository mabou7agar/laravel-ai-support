<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentRunSseStreamService
{
    public function __construct(
        private readonly AgentRunRepository $runs,
        private readonly AgentRunEventStreamService $events,
        private readonly ?AgentActivityPresenter $activity = null
    ) {}

    private function presenter(): AgentActivityPresenter
    {
        return $this->activity ?? app(AgentActivityPresenter::class);
    }

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
        $maxIdlePolls = max(0, (int) config('ai-agent.event_stream.sse.max_idle_polls', 0));
        $lastEventId = (string) ($options['last_event_id'] ?? '');

        return response()->stream(function () use ($run, $timeout, $pollMilliseconds, $heartbeatSeconds, $maxIdlePolls, $lastEventId): void {
            $deadline = microtime(true) + $timeout;
            $lastHeartbeat = microtime(true);
            $sent = [];
            $idlePolls = 0;

            while (microtime(true) <= $deadline) {
                // Stop holding the connection if the client has already gone away
                // (browser tab closed, navigation, etc.).
                if (connection_aborted() === 1) {
                    break;
                }

                $currentRun = $this->runs->find($run->id);
                if (!$currentRun instanceof AIAgentRun) {
                    break;
                }

                $emitted = $this->emitNewEvents($currentRun, $lastEventId, $sent);

                if ($currentRun->isTerminal()) {
                    // Re-fetch once: a terminal event (e.g. run.completed) may have
                    // been persisted in the transition window after the fetch above.
                    $finalRun = $this->runs->find($run->id);
                    if ($finalRun instanceof AIAgentRun) {
                        $this->emitNewEvents($finalRun, $lastEventId, $sent);
                    }
                    break;
                }

                // Inactivity guard: a never-terminal run would otherwise hold the
                // connection for the full timeout. Break after N consecutive polls
                // that produced no new events (0 disables the guard).
                $idlePolls = $emitted ? 0 : $idlePolls + 1;
                if ($maxIdlePolls > 0 && $idlePolls >= $maxIdlePolls) {
                    Log::channel('ai-engine')->info('Agent run SSE closed after idle timeout', [
                        'run_id' => $run->uuid,
                        'run_db_id' => $run->id,
                        'idle_polls' => $idlePolls,
                    ]);
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

    /**
     * @param  array<string, bool>  $sent
     * @return bool Whether at least one new event was emitted.
     */
    private function emitNewEvents(AIAgentRun $run, string $lastEventId, array &$sent): bool
    {
        $events = $this->eventsAfter($this->events->fallbackEvents($run), $lastEventId, $run);
        $emitted = false;
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
            $emitted = true;
        }

        return $emitted;
    }

    private function eventsAfter(array $events, string $lastEventId, ?AIAgentRun $run = null): array
    {
        if ($lastEventId === '') {
            return $events;
        }

        $found = false;

        $after = array_values(array_filter($events, static function (array $event) use (&$found, $lastEventId): bool {
            if ($found) {
                return true;
            }

            if ((string) ($event['id'] ?? '') === $lastEventId) {
                $found = true;
            }

            return false;
        }));

        if (!$found && $events !== []) {
            // The client asked to resume after an event we no longer have (it was
            // truncated from the persisted window or never persisted). Surface the
            // gap so SSE resume holes are observable instead of silently empty.
            Log::channel('ai-engine')->warning('Agent run SSE resume could not locate last_event_id', [
                'run_id' => $run?->uuid,
                'run_db_id' => $run?->id,
                'last_event_id' => $lastEventId,
                'available_events' => count($events),
            ]);
        }

        return $after;
    }

    private function frame(array $event): string
    {
        $name = (string) ($event['name'] ?? 'message');
        $id = (string) ($event['id'] ?? '');
        // Attach a human-friendly live activity label (icon + verb phrase + phase)
        // so the client can render a Claude-Code-style "Searching for customer…" line
        // without re-implementing event-to-label mapping.
        $event['activity'] = $this->presenter()->describe($name, (array) ($event['payload'] ?? []));
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
