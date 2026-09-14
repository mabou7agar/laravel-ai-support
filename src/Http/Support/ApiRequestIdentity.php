<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Support;

use Illuminate\Http\Request;

/**
 * Resolves who a package API request acts as.
 *
 * The authenticated user is authoritative. A request-body user_id, and scope keys
 * (workspace_id, tenant_id, ...) inside caller-supplied metadata, are only honoured when
 * the host opts in with `ai-engine.api.identity.trust_request_identity` — otherwise any
 * caller could impersonate another user or widen a tool's data scope.
 */
class ApiRequestIdentity
{
    public static function userId(Request $request, mixed $requestedUserId = null): ?string
    {
        $authId = $request->user()?->getAuthIdentifier();
        if ($authId !== null && $authId !== '') {
            return (string) $authId;
        }

        if (self::trustsRequestIdentity() && $requestedUserId !== null && $requestedUserId !== '') {
            return (string) $requestedUserId;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function metadata(array $metadata): array
    {
        if (self::trustsRequestIdentity()) {
            return $metadata;
        }

        $keys = (array) config('ai-engine.api.identity.scope_metadata_keys', []);

        return array_diff_key($metadata, array_flip(array_map('strval', $keys)));
    }

    public static function trustsRequestIdentity(): bool
    {
        return (bool) config('ai-engine.api.identity.trust_request_identity', false);
    }
}
