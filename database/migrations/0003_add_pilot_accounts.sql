ALTER TABLE users
    ADD COLUMN operational_role VARCHAR(20) NOT NULL DEFAULT 'teacher' AFTER display_name,
    ADD COLUMN is_admin BOOLEAN NOT NULL DEFAULT FALSE AFTER is_active,
    ADD COLUMN password_hash VARCHAR(255) NULL AFTER is_admin,
    ADD COLUMN account_state VARCHAR(32) NOT NULL DEFAULT 'claimed' AFTER password_hash,
    ADD UNIQUE KEY users_login (display_name),
    ADD CONSTRAINT users_operational_role_valid
        CHECK (operational_role IN ('teacher', 'technician')),
    ADD CONSTRAINT users_account_state_valid
        CHECK (account_state IN ('awaiting_first_login', 'claimed'));
