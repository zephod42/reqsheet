CREATE TABLE organisation_settings (
    organisation_id BIGINT UNSIGNED NOT NULL,
    working_days VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5',
    first_day_of_week TINYINT UNSIGNED NOT NULL DEFAULT 1,
    periods_per_day SMALLINT UNSIGNED NOT NULL DEFAULT 6,
    start_time TIME NULL,
    standard_period_minutes SMALLINT UNSIGNED NULL,
    custom_day_settings JSON NULL,
    separators JSON NULL,
    allow_double_periods BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organisation_id),
    CONSTRAINT organisation_settings_organisation_fk
        FOREIGN KEY (organisation_id) REFERENCES organisations (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT organisation_settings_first_day_valid CHECK (first_day_of_week BETWEEN 1 AND 7),
    CONSTRAINT organisation_settings_periods_valid CHECK (periods_per_day BETWEEN 1 AND 20),
    CONSTRAINT organisation_settings_standard_period_valid
        CHECK (standard_period_minutes IS NULL OR standard_period_minutes > 0)
) ENGINE=InnoDB;

CREATE TABLE organisation_rooms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NOT NULL,
    room_code VARCHAR(100) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY organisation_rooms_code (organisation_id, room_code),
    CONSTRAINT organisation_rooms_organisation_fk
        FOREIGN KEY (organisation_id) REFERENCES organisations (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT organisation_rooms_code_not_blank CHECK (CHAR_LENGTH(TRIM(room_code)) > 0)
) ENGINE=InnoDB;
