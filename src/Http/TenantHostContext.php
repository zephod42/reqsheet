<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final readonly class TenantHostContext
{
    /** @param array{id:int,name:string,tenant_slug:string}|null $organisation */
    public function __construct(
        public ?string $baseHost,
        public ?string $tenantSlug,
        public ?array $organisation,
    ) {
    }

    public function isTenant(): bool
    {
        return $this->tenantSlug !== null;
    }
}
