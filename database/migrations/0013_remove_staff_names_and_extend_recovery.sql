-- Preserve account identities and historical references; deleted accounts use is_active.
-- The legacy display-name check and login index depend on the column being removed.
ALTER TABLE users
    DROP CHECK users_display_name_not_blank,
    DROP INDEX users_login,
    DROP COLUMN display_name;

-- Preparation belongs to the dated occurrence, not the recurring timetable lesson.
ALTER TABLE lesson_occurrences
    ADD COLUMN prepared_at DATETIME(6) NULL AFTER updated_at;

-- Existing recovery flows continue to target their original user IDs.
-- New-account attempts reserve only initials until atomic password completion.
ALTER TABLE account_recovery_flows
    MODIFY COLUMN user_id BIGINT UNSIGNED NULL,
    ADD COLUMN requested_initials CHAR(3) NULL AFTER user_id,
    ADD CONSTRAINT account_recovery_flows_target_valid CHECK (
        (user_id IS NOT NULL AND requested_initials IS NULL)
        OR (user_id IS NULL AND requested_initials IS NOT NULL)
    );
