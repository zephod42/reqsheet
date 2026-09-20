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
        assertSameValue('kingswilliam', TenantSlug::suggest("King's William's College"), 'School name short-code suggestion was not normalized and bounded.');
        assertThrows(static fn () => TenantSlug::normalise('KWC'), 'Uppercase school short code was silently normalized.');
        assertSameValue('kwc', TenantSlug::normalise('kwc'), 'Valid lowercase school short code was rejected.');
        assertSameValue('sch4', TenantSlug::normalise('sch4'), 'Lowercase alphanumeric school short code was rejected.');
        foreach (['ab', 'bad slug', 'bad-code', '-school', 'school-', 'www', 'WWW', 'Www', 'localhost', str_repeat('a', 13)] as $invalid) {
            assertThrows(static fn () => TenantSlug::normalise($invalid), 'Invalid or reserved tenant slug was accepted: ' . $invalid);
        }
        try {
            TenantSlug::normalise('bad-code');
            throw new \RuntimeException('Punctuated school short code did not fail.');
        } catch (\Reqsheet\Account\AccountValidationException $exception) {
            assertSameValue('Use 3–12 lowercase letters or numbers.', $exception->errors()[0], 'School short-code guidance was not actionable.');
        }
        assertSameValue(true, TenantSlug::isRoutable('legacy-school'), 'Historical hyphenated school short code was not retained for routing compatibility.');
        try {
            TenantSlug::normalise('WwW');
            throw new \RuntimeException('Reserved www tenant slug did not fail.');
        } catch (\Reqsheet\Account\AccountValidationException $exception) {
            assertSameValue('This school short code is reserved. Please choose another.', $exception->errors()[0], 'Reserved www error message was incorrect.');
        }

        $store = new TenantStoreFake();
        $resolver = new TenantHostResolver($store);
        $root = $resolver->resolve('reqsheet.test', 'reqsheet.test');
        assertSameValue(false, $root->isTenant(), 'Configured root host was treated as a tenant.');
        $wwwRoot = $resolver->resolve('WWW.reqsheet.test', 'reqsheet.test');
        assertSameValue(false, $wwwRoot->isTenant(), 'The www public alias was treated as a tenant.');
        $known = $resolver->resolve('kwc.reqsheet.test', 'reqsheet.test');
        assertSameValue(true, $known->isTenant(), 'Known tenant host was not resolved.');
        assertSameValue(1, $known->organisation['id'], 'Known tenant resolved to the wrong organisation.');
        $otherBase = $resolver->resolve('kwc.example.test', 'example.test');
        assertSameValue(1, $otherBase->organisation['id'], 'Tenant slug did not work with another configured base domain.');
        $allowedBases = TenantHostResolver::configuredBaseHosts(['REQSHEET_BASE_HOSTS' => 'reqsheet.com, reqsheet.duckdns.org', 'REQSHEET_CANONICAL_HOST' => 'reqsheet.com'], ['SERVER_NAME' => 'untrusted.example']);
        assertSameValue(['reqsheet.com', 'reqsheet.duckdns.org'], $allowedBases, 'Both configured public domains were not retained.');
        assertSameValue('reqsheet.com', TenantHostResolver::canonicalPublicHost(['REQSHEET_CANONICAL_HOST' => 'reqsheet.com'], $allowedBases), 'Canonical public host was not selected.');
        assertSameValue('reqsheet.com', TenantHostResolver::baseHostForRequest('kwc.reqsheet.com', $allowedBases), 'New-domain tenant host was not matched.');
        assertSameValue('reqsheet.duckdns.org', TenantHostResolver::baseHostForRequest('kwc.reqsheet.duckdns.org', $allowedBases), 'DuckDNS tenant host was not retained.');
        $newDomain = $resolver->resolve('kwc.reqsheet.com', 'reqsheet.com');
        assertSameValue($known->organisation, $newDomain->organisation, 'The same tenant did not retain its organisation identity across domains.');
        foreach (['www.reqsheet.com', 'www.reqsheet.duckdns.org'] as $wwwHost) {
            $wwwBase = str_ends_with($wwwHost, '.reqsheet.com') ? 'reqsheet.com' : 'reqsheet.duckdns.org';
            assertSameValue(false, $resolver->resolve($wwwHost, $wwwBase)->isTenant(), 'The www alias was treated as a tenant for ' . $wwwBase . '.');
        }

        foreach (['missing.reqsheet.test', 'a.b.reqsheet.test', 'kwc.other.test', 'bad_slug.reqsheet.test'] as $host) {
            assertThrows(static fn () => $resolver->resolve($host, 'reqsheet.test'), 'Invalid or unknown tenant host was accepted: ' . $host);
        }
        assertSameValue('reqsheet.test', TenantHostResolver::configuredBaseHost(['REQSHEET_BASE_HOST' => 'reqsheet.test'], ['SERVER_NAME' => 'wrong.test']), 'Configured base host was ignored.');
        assertSameValue('localhost', TenantHostResolver::configuredBaseHost([], ['SERVER_NAME' => 'localhost']), 'Local server-name fallback failed.');
        assertSameValue(null, TenantHostResolver::configuredBaseHost([], ['SERVER_NAME' => 'reqsheet.example']), 'Unconfigured public server-name was trusted.');
        assertThrows(static fn () => TenantHostResolver::canonicalPublicHost(['REQSHEET_CANONICAL_HOST' => 'other.example'], $allowedBases), 'Canonical host outside the allowlist was accepted.');
    }
}

final class TenantStoreFake implements TenantStore
{
    public function findOrganisationByTenantSlug(string $tenantSlug): ?array
    {
        return $tenantSlug === 'kwc' ? ['id' => 1, 'name' => 'King William’s College', 'tenant_slug' => 'kwc'] : null;
    }
}
