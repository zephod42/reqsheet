ALTER TABLE users
    DROP COLUMN email,
    DROP CHECK users_account_state_valid;

ALTER TABLE users
    ADD CONSTRAINT users_account_state_valid
        CHECK (account_state IN ('awaiting_first_login', 'claimed', 'recovery_pending'));

ALTER TABLE organisations
    DROP COLUMN contact_email,
    ADD COLUMN recovery_key_digest BINARY(32) NULL AFTER tenant_slug,
    ADD COLUMN recovery_key_generation BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER recovery_key_digest,
    ADD COLUMN recovery_key_acknowledged_at DATETIME(6) NULL AFTER recovery_key_generation,
    ADD COLUMN subscription_state VARCHAR(16) NULL AFTER recovery_key_acknowledged_at,
    ADD COLUMN subscription_until DATE NULL AFTER subscription_state,
    ADD CONSTRAINT organisations_recovery_key_state_valid CHECK (
        (recovery_key_digest IS NULL AND recovery_key_generation = 0 AND recovery_key_acknowledged_at IS NULL)
        OR (recovery_key_digest IS NOT NULL AND recovery_key_generation > 0)
    ),
    ADD CONSTRAINT organisations_subscription_state_valid CHECK (
        (subscription_state IS NULL AND subscription_until IS NULL)
        OR (subscription_state IN ('free_trial', 'paid') AND subscription_until IS NOT NULL)
    );

CREATE TABLE account_recovery_flows (
    token_hash BINARY(32) NOT NULL,
    organisation_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    recovery_key_generation BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (token_hash),
    KEY account_recovery_flows_expiry (expires_at, consumed_at),
    KEY account_recovery_flows_organisation_generation (organisation_id, recovery_key_generation, consumed_at),
    CONSTRAINT account_recovery_flows_organisation_fk
        FOREIGN KEY (organisation_id) REFERENCES organisations (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT account_recovery_flows_user_fk
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE account_recovery_rate_limits (
    organisation_id BIGINT UNSIGNED NOT NULL,
    client_hash BINARY(32) NOT NULL,
    window_started_at DATETIME(6) NOT NULL,
    failure_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    blocked_until DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organisation_id, client_hash),
    KEY account_recovery_rate_limits_updated (updated_at),
    CONSTRAINT account_recovery_rate_limits_organisation_fk
        FOREIGN KEY (organisation_id) REFERENCES organisations (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;
