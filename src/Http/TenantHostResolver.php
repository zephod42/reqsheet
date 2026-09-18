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
        $configured = $environment['REQSHEET_BASE_HOST'] ?? null;
        if (is_string($configured) && trim($configured) !== '') return self::normaliseHost($configured, true);
        $serverName = $server['SERVER_NAME'] ?? '';
        if (is_string($serverName) && in_array(strtolower(trim($serverName)), ['localhost', '127.0.0.1'], true)) return self::normaliseHost($serverName, true);
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
