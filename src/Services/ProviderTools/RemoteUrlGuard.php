<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\ProviderTools;

/**
 * SSRF guard for server-side fetches of provider/LLM-supplied URLs.
 *
 * A download_url/source_url on an artifact originates from the provider's response
 * (and can be influenced by prompt injection or a compromised upstream), so before the
 * server fetches it we must reject URLs that point at internal infrastructure — cloud
 * metadata (169.254.169.254), localhost, RFC1918, and other private/reserved ranges.
 *
 * isFetchable() validates the scheme and resolves the host, rejecting if ANY resolved
 * address is private/reserved/loopback (so a public hostname with a private A-record is
 * caught, not just literal private IPs). Callers MUST also disable HTTP redirects, since
 * this validates a single URL — a public URL can 30x-redirect to an internal one.
 */
class RemoteUrlGuard
{
    public static function isFetchable(?string $url): bool
    {
        if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        // Escape hatch for trusted/offline environments (mirrors the artifact mirror guard).
        if ((bool) config('ai-engine.provider_tools.artifacts.block_private_urls', true) !== true) {
            return true;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        // Strip IPv6 brackets so a literal address ([::1]) validates as an IP.
        $literal = trim($host, '[]');
        $ips = filter_var($literal, FILTER_VALIDATE_IP) !== false
            ? [$literal]
            // Live DNS resolution (hostname -> private IP) is skipped in the testing
            // environment to stay deterministic regardless of the dev/CI resolver; the
            // literal-IP / localhost / scheme checks above always apply.
            : (app()->environment('testing') ? [] : self::resolveHost($host));

        // An unresolvable host can't be used for SSRF — the fetch itself just fails — so
        // we don't block it (this also keeps the guard usable offline / in tests). We
        // only reject when a host actually RESOLVES to a private/reserved/loopback address.
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private static function resolveHost(string $host): array
    {
        $ips = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) {
                $ips = $resolved;
            }
        }

        return array_values(array_unique($ips));
    }
}
