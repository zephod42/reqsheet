ALTER TABLE users
    MODIFY COLUMN operational_role VARCHAR(20) NULL DEFAULT NULL AFTER display_name,
    ADD COLUMN email VARCHAR(255) NULL AFTER staff_identifier,
    ADD COLUMN is_teacher BOOLEAN NOT NULL DEFAULT FALSE AFTER is_active,
    ADD COLUMN is_technician BOOLEAN NOT NULL DEFAULT FALSE AFTER is_teacher,
    ADD COLUMN teacher_number INT UNSIGNED NULL AFTER is_technician,
    ADD UNIQUE KEY users_organisation_teacher_number (organisation_id, teacher_number),
    ADD CONSTRAINT users_teacher_number_valid CHECK (teacher_number IS NULL OR teacher_number > 0);

UPDATE users
SET is_teacher = operational_role = 'teacher',
    is_technician = operational_role = 'technician';

UPDATE users u
JOIN (
    SELECT id, ROW_NUMBER() OVER (PARTITION BY organisation_id ORDER BY id) AS teacher_number
    FROM users
    WHERE is_teacher = TRUE
) numbered ON numbered.id = u.id
SET u.teacher_number = numbered.teacher_number;
