CREATE TABLE organisation_classes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NOT NULL,
    class_code VARCHAR(100) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY organisation_classes_code (organisation_id, class_code),
    CONSTRAINT organisation_classes_organisation_fk
        FOREIGN KEY (organisation_id) REFERENCES organisations (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT organisation_classes_code_not_blank CHECK (CHAR_LENGTH(TRIM(class_code)) > 0)
) ENGINE=InnoDB;

INSERT IGNORE INTO organisation_rooms (organisation_id, room_code)
SELECT DISTINCT tv.organisation_id, rl.room_code
FROM recurring_lessons rl
JOIN timetable_versions tv ON tv.id = rl.timetable_version_id
WHERE TRIM(rl.room_code) <> '';

INSERT IGNORE INTO organisation_classes (organisation_id, class_code)
SELECT DISTINCT tv.organisation_id, rl.class_code
FROM recurring_lessons rl
JOIN timetable_versions tv ON tv.id = rl.timetable_version_id
WHERE TRIM(rl.class_code) <> '';

ALTER TABLE recurring_lessons
    ADD COLUMN class_id BIGINT UNSIGNED NULL AFTER class_code,
    ADD COLUMN room_id BIGINT UNSIGNED NULL AFTER room_code;

UPDATE recurring_lessons rl
JOIN timetable_versions tv ON tv.id = rl.timetable_version_id
JOIN organisation_classes c ON c.organisation_id = tv.organisation_id AND c.class_code = rl.class_code
JOIN organisation_rooms r ON r.organisation_id = tv.organisation_id AND r.room_code = rl.room_code
SET rl.class_id = c.id, rl.room_id = r.id;

ALTER TABLE recurring_lessons
    MODIFY class_id BIGINT UNSIGNED NOT NULL,
    MODIFY room_id BIGINT UNSIGNED NOT NULL,
    ADD KEY recurring_lessons_class_version (class_id, timetable_version_id),
    ADD KEY recurring_lessons_room_version (room_id, timetable_version_id),
    ADD CONSTRAINT recurring_lessons_class_fk
        FOREIGN KEY (class_id) REFERENCES organisation_classes (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT recurring_lessons_room_fk
        FOREIGN KEY (room_id) REFERENCES organisation_rooms (id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
