<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use Reqsheet\Account\TenantSlug;
use Reqsheet\Account\TenantStore;

final class TenantHostResolver
{
    public function __construct(private readonly TenantStore $store)
    {
    }

    /** @param array<string, mixed> $environment @param array<string, mixed> $server */
    public static function configuredBaseHost(array $environment, array $server): ?string
    {
        $hosts = self::configuredBaseHosts($environment, $server);
        return $hosts[0] ?? null;
    }

    /** @param array<string, mixed> $environment @param array<string, mixed> $server @return list<string> */
    public static function configuredBaseHosts(array $environment, array $server): array
    {
        $configured = $environment['REQSHEET_BASE_HOSTS'] ?? ($environment['REQSHEET_BASE_HOST'] ?? null);
        if (is_string($configured) && trim($configured) !== '') {
            $hosts = [];
            foreach (preg_split('/\s*,\s*/', trim($configured)) ?: [] as $candidate) {
                if ($candidate === '') continue;
                $host = self::normaliseHost($candidate, true);
                if (!in_array($host, $hosts, true)) $hosts[] = $host;
            }
            return $hosts;
        }
        $serverName = $server['SERVER_NAME'] ?? '';
        if (is_string($serverName) && in_array(strtolower(trim($serverName)), ['localhost', '127.0.0.1'], true)) return [self::normaliseHost($serverName, true)];
        return [];
    }

    /** @param array<string, mixed> $environment @param list<string> $baseHosts */
    public static function canonicalPublicHost(array $environment, array $baseHosts): ?string
    {
        $configured = $environment['REQSHEET_CANONICAL_HOST'] ?? null;
        $canonical = is_string($configured) && trim($configured) !== '' ? self::normaliseHost($configured, true) : ($baseHosts[0] ?? null);
        if ($canonical === null) return null;
        if (!in_array($canonical, $baseHosts, true)) throw new TenantHostException('Canonical host is not an allowed base host.');
        return $canonical;
    }

    /** @param list<string> $baseHosts */
    public static function baseHostForRequest(string $requestHost, array $baseHosts): ?string
    {
        foreach ($baseHosts as $baseHost) {
            $baseHost = self::normaliseHost($baseHost, true);
            $normalised = self::normaliseHost($requestHost, false);
            if ($normalised === $baseHost || str_ends_with($normalised, '.' . $baseHost)) return $baseHost;
        }
        return null;
    }

    public function resolve(string $requestHost, string $baseHost): TenantHostContext
    {
        $baseHost = self::normaliseHost($baseHost, true);
        $requestHost = self::normaliseHost($requestHost, false);
        $slug = self::tenantSlugForHost($requestHost, $baseHost);
        if ($slug === null) return new TenantHostContext($baseHost, null, null);

        $organisation = $this->store->findOrganisationByTenantSlug($slug);
        if ($organisation === null) throw new TenantHostException('School was not found.');
        return new TenantHostContext($baseHost, $slug, $organisation);
    }

    public static function tenantSlugForHost(string $requestHost, string $baseHost): ?string
    {
        $baseHost = self::normaliseHost($baseHost, true);
        $requestHost = self::normaliseHost($requestHost, false);
        if ($requestHost === $baseHost) return null;
        $suffix = '.' . $baseHost;
        if (!str_ends_with($requestHost, $suffix)) throw new TenantHostException('Host is outside the configured base host.');
        $slug = substr($requestHost, 0, -strlen($suffix));
        // The conventional www alias belongs to the public base host, not to a school.
        if ($slug === 'www') return null;
        if ($slug === '' || str_contains($slug, '.') || !TenantSlug::isValid($slug)) throw new TenantHostException('Host is not a valid tenant host.');
        return $slug;
    }

    public static function requestHost(array $server): string
    {
        $host = $server['HTTP_HOST'] ?? '';
        if (!is_string($host) || trim($host) === '') throw new TenantHostException('Request host is missing.');
        return $host;
    }

    private static function normaliseHost(string $host, bool $allowIp): string
    {
        $host = strtolower(trim($host));
        if (str_ends_with($host, '.')) $host = rtrim($host, '.');
        if (substr_count($host, ':') === 1) {
            [$candidate, $port] = explode(':', $host, 2);
            if ($port === '' || filter_var($port, FILTER_VALIDATE_INT) === false) throw new TenantHostException('Host contains an invalid port.');
            $host = $candidate;
        }
        if ($host === '' || strlen($host) > 253) throw new TenantHostException('Host is invalid.');
        if ($allowIp && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) return $host;
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) throw new TenantHostException('IPv6 hosts are not supported for tenant routing.');
        $labels = explode('.', $host);
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label) !== 1) throw new TenantHostException('Host is invalid.');
        }
        return $host;
    }
}
