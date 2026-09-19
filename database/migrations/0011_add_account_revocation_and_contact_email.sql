ALTER TABLE organisations
    ADD COLUMN contact_email VARCHAR(255) NULL AFTER tenant_slug;

ALTER TABLE users
    ADD COLUMN auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER account_state;
