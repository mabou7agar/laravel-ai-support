<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\Models\AIAgentRun;

/**
 * Owner-scopes the agent-run REST endpoints (show, trace, list, resume, cancel).
 *
 * Agent runs hold the full conversation, tool payloads, and trace. Without this guard
 * any caller that knows (or guesses) a run id could read or control another user's run
 * (IDOR). This mirrors the SSE stream authorizer so every agent-run surface enforces the
 * same rule. Applied at the HTTP/controller boundary only — the underlying services are
 * also invoked internally by jobs and must not be gated by request-scoped ownership.
 *
 * Configured under `ai-agent.event_stream.access`:
 *   - authorize_owned_runs (default true): a run with a user_id is only accessible to
 *     that user; a mismatch (or missing auth) is refused with HTTP 403.
 *   - allow_anonymous_runs (default false): when true, runs that have no user_id are
 *     accessible without an authenticated user.
 *   - authorizer (default null): a callable or class with authorize($run, $authUserId)
 *     returning true to allow; overrides the built-in owner check for single-run access.
 */
class AgentRunAccessGuard
{
    public function authorize(AIAgentRun $run, int|string|null $authUserId): void
    {
        $authorizer = config('ai-agent.event_stream.access.authorizer');
        if ($authorizer !== null) {
            $resolved = is_string($authorizer) ? app($authorizer) : $authorizer;
            $allowed = is_callable($resolved)
                ? $resolved($run, $authUserId)
                : $resolved->authorize($run, $authUserId);

            if ($allowed !== true) {
                abort(403);
            }

            return;
        }

        if (!(bool) config('ai-agent.event_stream.access.authorize_owned_runs', true)) {
            return;
        }

        $runUserId = $run->user_id;
        if ($runUserId === null || $runUserId === '') {
            if (!(bool) config('ai-agent.event_stream.access.allow_anonymous_runs', false)) {
                abort(403);
            }

            return;
        }

        if ($authUserId === null || $authUserId === '' || (string) $authUserId !== (string) $runUserId) {
            abort(403);
        }
    }

    /**
     * Force the list filters to the resolved owner when the built-in owner check is on,
     * so a client-supplied user_id filter cannot widen the result set to another owner.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function scopeListFilters(array $filters, int|string|null $authUserId): array
    {
        // A custom authorizer governs single-run access and cannot be evaluated over a
        // list query; hosts that set one own their own list scoping.
        if (config('ai-agent.event_stream.access.authorizer') !== null) {
            return $filters;
        }

        if (!(bool) config('ai-agent.event_stream.access.authorize_owned_runs', true)) {
            return $filters;
        }

        if ($authUserId === null || $authUserId === '') {
            if ((bool) config('ai-agent.event_stream.access.allow_anonymous_runs', false)) {
                return $filters;
            }

            abort(403);
        }

        $filters['user_id'] = (string) $authUserId;

        return $filters;
    }
}
