CREATE TEMPORARY TABLE timetable_label_migration AS
SELECT id,
       CASE WHEN duplicate_count > 1 THEN CONCAT(base_label, ' (', id, ')') ELSE base_label END AS new_label
FROM (
    SELECT id, base_label,
           COUNT(*) OVER (PARTITION BY organisation_id, base_label) AS duplicate_count
    FROM (
        SELECT id, organisation_id,
               COALESCE(NULLIF(TRIM(label), ''), CONCAT('Timetable ', id)) AS base_label
        FROM timetable_versions
    ) named_versions
) numbered_versions;

UPDATE timetable_versions tv
JOIN timetable_label_migration lm ON lm.id = tv.id
SET tv.label = lm.new_label;

DROP TEMPORARY TABLE timetable_label_migration;

ALTER TABLE timetable_versions
    MODIFY COLUMN label VARCHAR(255) NOT NULL,
    ADD COLUMN first_day_of_week TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER label,
    DROP INDEX timetable_versions_organisation_start;

UPDATE timetable_versions tv
LEFT JOIN organisation_settings os ON os.organisation_id = tv.organisation_id
SET tv.first_day_of_week = COALESCE(os.first_day_of_week, 1);

ALTER TABLE timetable_versions
    ADD UNIQUE KEY timetable_versions_organisation_label (organisation_id, label),
    ADD CONSTRAINT timetable_versions_first_day_valid CHECK (first_day_of_week BETWEEN 1 AND 7);

ALTER TABLE organisations
    ADD COLUMN active_timetable_version_id BIGINT UNSIGNED NULL AFTER tenant_slug,
    ADD KEY organisations_active_timetable (active_timetable_version_id),
    ADD CONSTRAINT organisations_active_timetable_fk
        FOREIGN KEY (active_timetable_version_id) REFERENCES timetable_versions (id)
        ON DELETE SET NULL ON UPDATE CASCADE;
