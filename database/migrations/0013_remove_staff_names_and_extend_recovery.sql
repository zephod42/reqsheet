-- MySQL commits each DDL statement independently. These information-schema
-- guards allow recovery when an earlier 0013 attempt committed only its first
-- statements before failing, while remaining safe on a clean 0012 schema.
SET @reqsheet_0013_schema = DATABASE();

-- Preserve account identities and historical references; deleted accounts use is_active.
-- The legacy display-name check and login index depend on the column being removed.
SET @reqsheet_0013_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = @reqsheet_0013_schema
          AND TABLE_NAME = 'users'
          AND CONSTRAINT_NAME = 'users_display_name_not_blank'
          AND CONSTRAINT_TYPE = 'CHECK'
    ),
    'ALTER TABLE users DROP CHECK users_display_name_not_blank',
    'DO 0'
);
PREPARE reqsheet_0013_stmt FROM @reqsheet_0013_sql;
EXECUTE reqsheet_0013_stmt;
DEALLOCATE PREPARE reqsheet_0013_stmt;

SET @reqsheet_0013_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = @reqsheet_0013_schema
          AND TABLE_NAME = 'users'
          AND INDEX_NAME = 'users_login'
    ),
    'ALTER TABLE users DROP INDEX users_login',
    'DO 0'
);
PREPARE reqsheet_0013_stmt FROM @reqsheet_0013_sql;
EXECUTE reqsheet_0013_stmt;
DEALLOCATE PREPARE reqsheet_0013_stmt;

SET @reqsheet_0013_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @reqsheet_0013_schema
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'display_name'
    ),
    'ALTER TABLE users DROP COLUMN display_name',
    'DO 0'
);
PREPARE reqsheet_0013_stmt FROM @reqsheet_0013_sql;
EXECUTE reqsheet_0013_stmt;
DEALLOCATE PREPARE reqsheet_0013_stmt;

-- Preparation belongs to the dated occurrence, not the recurring timetable lesson.
SET @reqsheet_0013_sql = IF(
    NOT EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @reqsheet_0013_schema
          AND TABLE_NAME = 'lesson_occurrences'
          AND COLUMN_NAME = 'prepared_at'
    ),
    'ALTER TABLE lesson_occurrences ADD COLUMN prepared_at DATETIME(6) NULL AFTER updated_at',
    'DO 0'
);
PREPARE reqsheet_0013_stmt FROM @reqsheet_0013_sql;
EXECUTE reqsheet_0013_stmt;
DEALLOCATE PREPARE reqsheet_0013_stmt;

-- Existing recovery flows continue to target their original user IDs. The
-- foreign key and its cascading actions remain in place; NULL is used only for
-- a new-account recovery flow whose requested initials do not yet have a user.
SET @reqsheet_0013_sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @reqsheet_0013_schema
          AND TABLE_NAME = 'account_recovery_flows'
          AND COLUMN_NAME = 'user_id'
          AND IS_NULLABLE = 'NO'
    ),
    'ALTER TABLE account_recovery_flows MODIFY COLUMN user_id BIGINT UNSIGNED NULL',
    'DO 0'
);
PREPARE reqsheet_0013_stmt FROM @reqsheet_0013_sql;
EXECUTE reqsheet_0013_stmt;
DEALLOCATE PREPARE reqsheet_0013_stmt;

SET @reqsheet_0013_sql = IF(
    NOT EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @reqsheet_0013_schema
          AND TABLE_NAME = 'account_recovery_flows'
          AND COLUMN_NAME = 'requested_initials'
    ),
    'ALTER TABLE account_recovery_flows ADD COLUMN requested_initials CHAR(3) NULL AFTER user_id',
    'DO 0'
);
PREPARE reqsheet_0013_stmt FROM @reqsheet_0013_sql;
EXECUTE reqsheet_0013_stmt;
DEALLOCATE PREPARE reqsheet_0013_stmt;

-- MySQL prohibits a CHECK from referencing user_id because that column has
-- ON DELETE/UPDATE CASCADE referential actions. The target XOR and the strict
-- three-uppercase-letter rule are therefore enforced by the recovery store at
-- both flow creation and flow completion.
SET @reqsheet_0013_schema = NULL;
SET @reqsheet_0013_sql = NULL;
