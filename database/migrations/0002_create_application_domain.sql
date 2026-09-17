CREATE TABLE organisations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    CONSTRAINT organisations_name_not_blank CHECK (CHAR_LENGTH(TRIM(name)) > 0)
) ENGINE=InnoDB;

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    staff_identifier VARCHAR(100) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY users_organisation_staff_identifier (organisation_id, staff_identifier),
    KEY users_organisation_active (organisation_id, is_active),
    CONSTRAINT users_organisation_fk
        FOREIGN KEY (organisation_id) REFERENCES organisations (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT users_display_name_not_blank CHECK (CHAR_LENGTH(TRIM(display_name)) > 0)
) ENGINE=InnoDB;

CREATE TABLE timetable_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(255) NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY timetable_versions_organisation_start (organisation_id, effective_from),
    KEY timetable_versions_organisation_end (organisation_id, effective_to),
    CONSTRAINT timetable_versions_organisation_fk
        FOREIGN KEY (organisation_id) REFERENCES organisations (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT timetable_versions_date_range_valid
        CHECK (effective_to IS NULL OR effective_to > effective_from)
) ENGINE=InnoDB;

CREATE TABLE timetable_slots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    timetable_version_id BIGINT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    sequence_number SMALLINT UNSIGNED NOT NULL,
    kind VARCHAR(20) NOT NULL,
    teaching_period_number SMALLINT UNSIGNED NULL,
    label VARCHAR(100) NOT NULL,
    starts_at TIME NOT NULL,
    ends_at TIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY timetable_slots_version_day_sequence (timetable_version_id, day_of_week, sequence_number),
    UNIQUE KEY timetable_slots_version_day_period (timetable_version_id, day_of_week, teaching_period_number),
    KEY timetable_slots_version_day (timetable_version_id, day_of_week),
    CONSTRAINT timetable_slots_version_fk
        FOREIGN KEY (timetable_version_id) REFERENCES timetable_versions (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT timetable_slots_day_valid CHECK (day_of_week BETWEEN 1 AND 7),
    CONSTRAINT timetable_slots_sequence_valid CHECK (sequence_number > 0),
    CONSTRAINT timetable_slots_kind_valid
        CHECK (kind IN ('teaching', 'break', 'lunch', 'non_teaching')),
    CONSTRAINT timetable_slots_period_kind_consistent
        CHECK ((kind = 'teaching' AND teaching_period_number IS NOT NULL)
            OR (kind <> 'teaching' AND teaching_period_number IS NULL)),
    CONSTRAINT timetable_slots_period_valid
        CHECK (teaching_period_number IS NULL OR teaching_period_number > 0),
    CONSTRAINT timetable_slots_time_range_valid CHECK (starts_at < ends_at)
) ENGINE=InnoDB;

CREATE TABLE recurring_lessons (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    timetable_version_id BIGINT UNSIGNED NOT NULL,
    teacher_user_id BIGINT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_slot_id BIGINT UNSIGNED NOT NULL,
    duration_periods SMALLINT UNSIGNED NOT NULL,
    class_code VARCHAR(100) NOT NULL,
    room_code VARCHAR(100) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY recurring_lessons_teacher_day (teacher_user_id, day_of_week),
    KEY recurring_lessons_version_day_slot (timetable_version_id, day_of_week, start_slot_id),
    CONSTRAINT recurring_lessons_version_fk
        FOREIGN KEY (timetable_version_id) REFERENCES timetable_versions (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT recurring_lessons_teacher_fk
        FOREIGN KEY (teacher_user_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT recurring_lessons_start_slot_fk
        FOREIGN KEY (start_slot_id) REFERENCES timetable_slots (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT recurring_lessons_day_valid CHECK (day_of_week BETWEEN 1 AND 7),
    CONSTRAINT recurring_lessons_duration_valid CHECK (duration_periods >= 1),
    CONSTRAINT recurring_lessons_class_not_blank CHECK (CHAR_LENGTH(TRIM(class_code)) > 0),
    CONSTRAINT recurring_lessons_room_not_blank CHECK (CHAR_LENGTH(TRIM(room_code)) > 0)
) ENGINE=InnoDB;

CREATE TABLE lesson_occurrences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NOT NULL,
    recurring_lesson_id BIGINT UNSIGNED NOT NULL,
    timetable_version_id BIGINT UNSIGNED NOT NULL,
    lesson_date DATE NOT NULL,
    snapshot_teacher_user_id BIGINT UNSIGNED NOT NULL,
    snapshot_class_code VARCHAR(100) NOT NULL,
    snapshot_room_code VARCHAR(100) NOT NULL,
    snapshot_start_slot_id BIGINT UNSIGNED NOT NULL,
    snapshot_duration_periods SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY lesson_occurrences_organisation_lesson (organisation_id, lesson_date, recurring_lesson_id),
    KEY lesson_occurrences_teacher_date (organisation_id, snapshot_teacher_user_id, lesson_date),
    KEY lesson_occurrences_room_date (organisation_id, lesson_date, snapshot_room_code),
    CONSTRAINT lesson_occurrences_organisation_fk
        FOREIGN KEY (organisation_id) REFERENCES organisations (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT lesson_occurrences_recurring_lesson_fk
        FOREIGN KEY (recurring_lesson_id) REFERENCES recurring_lessons (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT lesson_occurrences_version_fk
        FOREIGN KEY (timetable_version_id) REFERENCES timetable_versions (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT lesson_occurrences_teacher_fk
        FOREIGN KEY (snapshot_teacher_user_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT lesson_occurrences_start_slot_fk
        FOREIGN KEY (snapshot_start_slot_id) REFERENCES timetable_slots (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT lesson_occurrences_class_not_blank CHECK (CHAR_LENGTH(TRIM(snapshot_class_code)) > 0),
    CONSTRAINT lesson_occurrences_room_not_blank CHECK (CHAR_LENGTH(TRIM(snapshot_room_code)) > 0),
    CONSTRAINT lesson_occurrences_duration_valid CHECK (snapshot_duration_periods >= 1)
) ENGINE=InnoDB;

CREATE TABLE requisitions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lesson_occurrence_id BIGINT UNSIGNED NOT NULL,
    state VARCHAR(32) NOT NULL,
    requirements_text TEXT NULL,
    planning_notes TEXT NULL,
    risk_assessment_text TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY requisitions_lesson_occurrence (lesson_occurrence_id),
    CONSTRAINT requisitions_lesson_occurrence_fk
        FOREIGN KEY (lesson_occurrence_id) REFERENCES lesson_occurrences (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT requisitions_state_valid
        CHECK (state IN ('not_completed', 'nothing_required', 'requirements_entered'))
) ENGINE=InnoDB;
