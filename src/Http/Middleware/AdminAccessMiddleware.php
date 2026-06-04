<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class AdminAccessMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $accessConfig = config('ai-engine.admin_ui.access', []);

        $userAllowed = $this->isUserAllowed($request->user(), $accessConfig);
        $ipAllowed = $this->isIpAllowed($request->ip(), $accessConfig);

        if (!$userAllowed && !$ipAllowed) {
            abort(403, 'Access denied.');
        }

        return $next($request);
    }

    protected function isUserAllowed(?Authenticatable $user, array $accessConfig): bool
    {
        if (!$user) {
            return false;
        }

        $allowedUserIds = $this->normalizeList($accessConfig['allowed_user_ids'] ?? []);
        $allowedEmails = array_map('strtolower', $this->normalizeList($accessConfig['allowed_emails'] ?? []));

        // Fail closed: an empty allowlist grants access to ANY authenticated user ONLY
        // when the host explicitly opts in. Otherwise admin requires being named in the
        // user-id or email allowlist. (Previously empty meant "everyone" — privilege
        // escalation for any logged-in user once the admin UI was enabled.)
        if ($allowedUserIds === [] && $allowedEmails === []) {
            return (bool) ($accessConfig['allow_any_authenticated'] ?? false);
        }

        $identifier = (string) $user->getAuthIdentifier();
        if ($identifier !== '' && in_array($identifier, $allowedUserIds, true)) {
            return true;
        }

        $email = strtolower((string) data_get($user, 'email', ''));

        return $email !== '' && in_array($email, $allowedEmails, true);
    }

    protected function isIpAllowed(?string $ip, array $accessConfig): bool
    {
        if (!is_string($ip) || trim($ip) === '') {
            return false;
        }

        $allowedIps = $this->normalizeList($accessConfig['allowed_ips'] ?? []);

        // Fail closed: do NOT implicitly trust localhost. Granting admin to any
        // unauthenticated localhost-origin request (including proxies that forward as
        // 127.0.0.1) is an escalation path. Opt in explicitly via allow_localhost.
        if ((bool) ($accessConfig['allow_localhost'] ?? false)) {
            $allowedIps = array_values(array_unique(array_merge($allowedIps, ['127.0.0.1', '::1'])));
        }

        foreach ($allowedIps as $candidate) {
            try {
                if (IpUtils::checkIp($ip, $candidate)) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (!is_string($item) && !is_numeric($item)) {
                continue;
            }

            $trimmed = trim((string) $item);
            if ($trimmed === '') {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return array_values(array_unique($normalized));
    }
}
