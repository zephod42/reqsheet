<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use Reqsheet\Account\TenantSlug;
use Reqsheet\Account\TenantStore;
use Reqsheet\Http\TenantHostResolver;

final class TenantTest
{
    public static function run(): void
    {
        assertSameValue('kings-williams-college', TenantSlug::suggest("King's William's College"), 'School name slug suggestion was not normalized.');
        assertSameValue('kwc', TenantSlug::normalise('KWC'), 'Tenant slug was not normalized to lowercase.');
        foreach (['bad slug', '-school', 'school-', 'www', 'localhost', str_repeat('a', 64)] as $invalid) {
            assertThrows(static fn () => TenantSlug::normalise($invalid), 'Invalid or reserved tenant slug was accepted: ' . $invalid);
        }

        $store = new TenantStoreFake();
        $resolver = new TenantHostResolver($store);
        $root = $resolver->resolve('reqsheet.test', 'reqsheet.test');
        assertSameValue(false, $root->isTenant(), 'Configured root host was treated as a tenant.');
        $known = $resolver->resolve('kwc.reqsheet.test', 'reqsheet.test');
        assertSameValue(true, $known->isTenant(), 'Known tenant host was not resolved.');
        assertSameValue(1, $known->organisation['id'], 'Known tenant resolved to the wrong organisation.');
        $otherBase = $resolver->resolve('kwc.example.test', 'example.test');
        assertSameValue(1, $otherBase->organisation['id'], 'Tenant slug did not work with another configured base domain.');

        foreach (['missing.reqsheet.test', 'a.b.reqsheet.test', 'kwc.other.test', 'bad_slug.reqsheet.test'] as $host) {
            assertThrows(static fn () => $resolver->resolve($host, 'reqsheet.test'), 'Invalid or unknown tenant host was accepted: ' . $host);
        }
        assertSameValue('reqsheet.test', TenantHostResolver::configuredBaseHost(['REQSHEET_BASE_HOST' => 'reqsheet.test'], ['SERVER_NAME' => 'wrong.test']), 'Configured base host was ignored.');
        assertSameValue('localhost', TenantHostResolver::configuredBaseHost([], ['SERVER_NAME' => 'localhost']), 'Local server-name fallback failed.');
        assertSameValue(null, TenantHostResolver::configuredBaseHost([], ['SERVER_NAME' => 'reqsheet.example']), 'Unconfigured public server-name was trusted.');
    }
}

final class TenantStoreFake implements TenantStore
{
    public function findOrganisationByTenantSlug(string $tenantSlug): ?array
    {
        return $tenantSlug === 'kwc' ? ['id' => 1, 'name' => 'King William’s College', 'tenant_slug' => 'kwc'] : null;
    }
}
