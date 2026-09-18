ALTER TABLE organisations
    ADD COLUMN tenant_slug VARCHAR(63) NULL AFTER name;

UPDATE organisations
SET tenant_slug = CONCAT('organisation-', id)
WHERE tenant_slug IS NULL;

ALTER TABLE organisations
    MODIFY tenant_slug VARCHAR(63) NOT NULL,
    ADD UNIQUE KEY organisations_tenant_slug (tenant_slug);
