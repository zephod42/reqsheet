CREATE TABLE onboarding_handoffs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash BINARY(32) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    organisation_id BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY onboarding_handoffs_token (token_hash),
    KEY onboarding_handoffs_expiry (expires_at, consumed_at),
    CONSTRAINT onboarding_handoffs_user_fk
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT onboarding_handoffs_organisation_fk
        FOREIGN KEY (organisation_id) REFERENCES organisations (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;
