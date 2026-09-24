ALTER TABLE organisation_settings
    ADD COLUMN technician_highlighting_enabled BOOLEAN NOT NULL DEFAULT FALSE AFTER date_format,
    ADD COLUMN technician_highlighting_colours JSON NULL AFTER technician_highlighting_enabled;

ALTER TABLE lesson_occurrences
    ADD COLUMN technician_highlighting_colour TINYINT UNSIGNED NULL AFTER prepared_at,
    ADD CONSTRAINT lesson_occurrences_technician_highlight_valid
        CHECK (technician_highlighting_colour IS NULL OR technician_highlighting_colour BETWEEN 1 AND 5);
