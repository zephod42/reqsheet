ALTER TABLE organisation_settings
    ADD COLUMN date_format VARCHAR(10) NOT NULL DEFAULT 'DD/MM/YYYY' AFTER allow_double_periods;
