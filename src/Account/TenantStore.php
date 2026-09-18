<?php

declare(strict_types=1);

namespace Reqsheet\Account;

interface TenantStore
{
    /** @return array{id:int,name:string,tenant_slug:string}|null */
    public function findOrganisationByTenantSlug(string $tenantSlug): ?array;
}
