ALTER TABLE organisation_rooms
    ADD COLUMN archived_at DATETIME(6) NULL AFTER created_at,
    ADD KEY organisation_rooms_active (organisation_id, archived_at, room_code, id);
