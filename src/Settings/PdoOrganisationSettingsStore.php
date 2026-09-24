<?php

declare(strict_types=1);

namespace Reqsheet\Settings;

use PDO;
use PDOStatement;

final class PdoOrganisationSettingsStore implements OrganisationSettingsStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(int $organisationId): array
    {
        $organisation = $this->prepare('SELECT name FROM organisations WHERE id = :id');
        $organisation->execute(['id' => $organisationId]);
        $name = $organisation->fetchColumn();
        if ($name === false) throw new \RuntimeException('Organisation does not exist.');

        $settings = $this->prepare(
            'SELECT working_days, first_day_of_week, periods_per_day, start_time,
                    standard_period_minutes, custom_day_settings, separators, allow_double_periods, date_format,
                    technician_highlighting_enabled, technician_highlighting_colours
             FROM organisation_settings WHERE organisation_id = :id',
        );
        $settings->execute(['id' => $organisationId]);
        $row = $settings->fetch();
        $rooms = $this->prepare('SELECT room_code FROM organisation_rooms WHERE organisation_id = :id ORDER BY room_code, id');
        $rooms->execute(['id' => $organisationId]);

        $roomCodes = array_map('strval', $rooms->fetchAll(PDO::FETCH_COLUMN));
        return [
            'school_name' => (string) $name,
            'working_days' => $row === false ? [] : self::days((string) $row['working_days']),
            'first_day_of_week' => $row === false ? 1 : (int) $row['first_day_of_week'],
            'periods_per_day' => $row === false ? 6 : (int) $row['periods_per_day'],
            'start_time' => $row === false || $row['start_time'] === null ? '' : substr((string) $row['start_time'], 0, 5),
            'standard_period_minutes' => $row === false || $row['standard_period_minutes'] === null ? '' : (string) $row['standard_period_minutes'],
            'custom_day_settings' => $row === false ? [] : self::jsonObject($row['custom_day_settings']),
            'separators' => $row === false ? [] : self::jsonList($row['separators']),
            'allow_double_periods' => $row !== false && (bool) $row['allow_double_periods'],
            'date_format' => $row === false ? 'DD/MM/YYYY' : (string) ($row['date_format'] ?? 'DD/MM/YYYY'),
            'technician_highlighting_enabled' => $row !== false && (bool) ($row['technician_highlighting_enabled'] ?? false),
            'technician_highlighting_colours' => $row === false ? [] : self::jsonListStrings($row['technician_highlighting_colours'] ?? null),
            'rooms' => $roomCodes,
            'complete' => $row !== false,
        ];
    }

    public function save(int $organisationId, array $settings, ?array $rooms = null): void
    {
        if (!$this->pdo->beginTransaction()) throw new \RuntimeException('Unable to begin settings save.');
        try {
            $organisation = $this->prepare('UPDATE organisations SET name = :name WHERE id = :id');
            $organisation->execute(['name' => $settings['school_name'], 'id' => $organisationId]);
            $statement = $this->prepare(
                'INSERT INTO organisation_settings
                    (organisation_id, working_days, first_day_of_week, periods_per_day, start_time,
                     standard_period_minutes, custom_day_settings, separators, allow_double_periods, date_format,
                     technician_highlighting_enabled, technician_highlighting_colours)
                 VALUES (:id, :days, :first_day, :periods, :start_time, :period_length, :custom_days, :separators, :double_periods, :date_format, :highlighting_enabled, :highlighting_colours)
                 ON DUPLICATE KEY UPDATE
                    working_days = VALUES(working_days), first_day_of_week = VALUES(first_day_of_week),
                    periods_per_day = VALUES(periods_per_day), start_time = VALUES(start_time),
                    standard_period_minutes = VALUES(standard_period_minutes), custom_day_settings = VALUES(custom_day_settings),
                    separators = VALUES(separators), allow_double_periods = VALUES(allow_double_periods), date_format = VALUES(date_format),
                    technician_highlighting_enabled = VALUES(technician_highlighting_enabled), technician_highlighting_colours = VALUES(technician_highlighting_colours)',
            );
            $statement->execute([
                'id' => $organisationId,
                'days' => implode(',', $settings['working_days']),
                'first_day' => $settings['first_day_of_week'],
                'periods' => $settings['periods_per_day'],
                'start_time' => $settings['start_time'] === '' ? null : $settings['start_time'] . ':00',
                'period_length' => $settings['standard_period_minutes'] === '' ? null : $settings['standard_period_minutes'],
                'custom_days' => json_encode($settings['custom_day_settings'], JSON_THROW_ON_ERROR),
                'separators' => json_encode($settings['separators'], JSON_THROW_ON_ERROR),
                'double_periods' => $settings['allow_double_periods'] ? 1 : 0,
                'date_format' => $settings['date_format'] ?? 'DD/MM/YYYY',
                'highlighting_enabled' => !empty($settings['technician_highlighting_enabled']) ? 1 : 0,
                'highlighting_colours' => json_encode(array_values((array) ($settings['technician_highlighting_colours'] ?? [])), JSON_THROW_ON_ERROR),
            ]);
            $count = count((array) ($settings['technician_highlighting_colours'] ?? []));
            $clear = $this->prepare('UPDATE lesson_occurrences SET technician_highlighting_colour = NULL WHERE organisation_id = :id AND (technician_highlighting_colour IS NOT NULL AND technician_highlighting_colour > :count)');
            $clear->execute(['id' => $organisationId, 'count' => $count]);
            if ($rooms !== null) {
                $existingRoomCodes = $this->prepare('SELECT room_code FROM organisation_rooms WHERE organisation_id = :id ORDER BY room_code');
                $existingRoomCodes->execute(['id' => $organisationId]);
                $existing = array_map(static fn (mixed $code): string => strtolower((string) $code), $existingRoomCodes->fetchAll(PDO::FETCH_COLUMN));
                $requested = array_map(static fn (mixed $code): string => strtolower((string) $code), $rooms);
                sort($existing); sort($requested);
                if ($existing !== $requested) {
                    $delete = $this->prepare('DELETE FROM organisation_rooms WHERE organisation_id = :id');
                    $delete->execute(['id' => $organisationId]);
                    $room = $this->prepare('INSERT INTO organisation_rooms (organisation_id, room_code) VALUES (:id, :code)');
                    foreach ($rooms as $code) $room->execute(['id' => $organisationId, 'code' => $code]);
                }
            }
            if (!$this->pdo->commit()) throw new \RuntimeException('Unable to complete settings save.');
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @return list<int> */
    private static function days(string $value): array
    {
        return array_values(array_filter(array_map('intval', explode(',', $value)), static fn (int $day): bool => $day >= 1 && $day <= 7));
    }

    /** @return array<string, mixed> */
    private static function jsonObject(?string $value): array
    {
        $decoded = $value === null ? [] : json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<array<string, mixed>> */
    private static function jsonList(?string $value): array
    {
        $decoded = $value === null ? [] : json_decode($value, true);
        if (!is_array($decoded)) return [];
        return array_values(array_filter($decoded, 'is_array'));
    }

    /** @return list<string> */
    private static function jsonListStrings(?string $value): array
    {
        $decoded = $value === null ? [] : json_decode($value, true);
        if (!is_array($decoded)) return [];
        return array_values(array_map('strval', $decoded));
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) throw new \RuntimeException('Unable to prepare settings query.');
        return $statement;
    }
}
