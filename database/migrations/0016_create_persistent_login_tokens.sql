CREATE TABLE persistent_login_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    organisation_id BIGINT UNSIGNED NOT NULL,
    selector CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    verifier_hash BINARY(32) NOT NULL,
    previous_verifier_hash BINARY(32) NULL,
    previous_valid_until DATETIME(6) NULL,
    auth_version BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    last_used_at DATETIME(6) NULL,
    expires_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY persistent_login_tokens_selector (selector),
    KEY persistent_login_tokens_user_expiry (user_id, expires_at),
    KEY persistent_login_tokens_organisation_expiry (organisation_id, expires_at),
    CONSTRAINT persistent_login_tokens_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT persistent_login_tokens_organisation_fk FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE
) ENGINE=InnoDB;
