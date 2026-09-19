CREATE TABLE technician_room_preferences (
    organisation_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    room_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organisation_id, user_id, room_id),
    KEY technician_room_preferences_user (user_id),
    CONSTRAINT technician_room_preferences_organisation_fk FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT technician_room_preferences_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT technician_room_preferences_room_fk FOREIGN KEY (room_id) REFERENCES organisation_rooms (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;
