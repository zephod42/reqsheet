<?php

declare(strict_types=1);

namespace Reqsheet\Monitor;

use PDO;

final class PdoMonitorStore
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function snapshot(): MonitorSnapshot
    {
        $this->assertReadOnlyAccount();

        $totals = [
            'organisations' => $this->count('organisations'),
            'users' => $this->count('users'),
            'requisitions' => $this->count('requisitions'),
            'timetable_versions' => $this->count('timetable_versions'),
        ];

        $schools = $this->connection->query(
            'SELECT o.name, o.tenant_slug AS short_code,
                    (SELECT COUNT(*) FROM users u WHERE u.organisation_id = o.id) AS users,
                    (SELECT COUNT(*) FROM timetable_versions tv WHERE tv.organisation_id = o.id) AS timetables,
                    (SELECT COUNT(*) FROM requisitions r
                     INNER JOIN lesson_occurrences lo ON lo.id = r.lesson_occurrence_id
                     WHERE lo.organisation_id = o.id) AS requisitions
             FROM organisations o
             ORDER BY o.name, o.id',
        )->fetchAll();

        $schoolRows = array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'short_code' => (string) $row['short_code'],
            'users' => (int) $row['users'],
            'timetables' => (int) $row['timetables'],
            'requisitions' => (int) $row['requisitions'],
        ], $schools);

        return new MonitorSnapshot($totals, $schoolRows, $this->activity(), $this->migrations());
    }

    private function assertReadOnlyAccount(): void
    {
        $statement = $this->connection->query('SHOW GRANTS FOR CURRENT_USER');
        $grants = array_map(
            static fn (array $row): string => (string) array_values($row)[0],
            $statement->fetchAll(),
        );
        ReadOnlyGrantValidator::assertSelectOnly($grants);
    }

    private function count(string $table): int
    {
        $allowed = ['organisations', 'users', 'requisitions', 'timetable_versions'];
        if (!in_array($table, $allowed, true)) {
            throw new \LogicException('Unsupported monitor table.');
        }
        return (int) $this->connection->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    /** @return array{registrations_7:?int,registrations_30:?int,requisitions_created_7:?int,requisitions_created_30:?int,requisitions_modified_7:?int,requisitions_modified_30:?int} */
    private function activity(): array
    {
        $activity = [
            'registrations_7' => null,
            'registrations_30' => null,
            'requisitions_created_7' => null,
            'requisitions_created_30' => null,
            'requisitions_modified_7' => null,
            'requisitions_modified_30' => null,
        ];

        if ($this->columnExists('organisations', 'created_at')) {
            $row = $this->connection->query(
                'SELECT SUM(created_at >= CURRENT_TIMESTAMP(6) - INTERVAL 7 DAY) AS last_7,
                        SUM(created_at >= CURRENT_TIMESTAMP(6) - INTERVAL 30 DAY) AS last_30
                 FROM organisations',
            )->fetch();
            $activity['registrations_7'] = (int) ($row['last_7'] ?? 0);
            $activity['registrations_30'] = (int) ($row['last_30'] ?? 0);
        }

        $hasCreated = $this->columnExists('requisitions', 'created_at');
        $hasUpdated = $this->columnExists('requisitions', 'updated_at');
        if ($hasCreated) {
            $row = $this->connection->query(
                'SELECT SUM(created_at >= CURRENT_TIMESTAMP(6) - INTERVAL 7 DAY) AS last_7,
                        SUM(created_at >= CURRENT_TIMESTAMP(6) - INTERVAL 30 DAY) AS last_30
                 FROM requisitions',
            )->fetch();
            $activity['requisitions_created_7'] = (int) ($row['last_7'] ?? 0);
            $activity['requisitions_created_30'] = (int) ($row['last_30'] ?? 0);
        }
        if ($hasCreated && $hasUpdated) {
            $row = $this->connection->query(
                'SELECT SUM(updated_at > created_at AND updated_at >= CURRENT_TIMESTAMP(6) - INTERVAL 7 DAY) AS last_7,
                        SUM(updated_at > created_at AND updated_at >= CURRENT_TIMESTAMP(6) - INTERVAL 30 DAY) AS last_30
                 FROM requisitions',
            )->fetch();
            $activity['requisitions_modified_7'] = (int) ($row['last_7'] ?? 0);
            $activity['requisitions_modified_30'] = (int) ($row['last_30'] ?? 0);
        }

        return $activity;
    }

    /** @return list<array{version:string,applied_at:?string}> */
    private function migrations(): array
    {
        if (!$this->tableExists('schema_migrations')) {
            return [];
        }
        $withTimestamp = $this->columnExists('schema_migrations', 'applied_at');
        $sql = $withTimestamp
            ? 'SELECT version, applied_at FROM schema_migrations ORDER BY version'
            : 'SELECT version, NULL AS applied_at FROM schema_migrations ORDER BY version';
        return array_map(static fn (array $row): array => [
            'version' => (string) $row['version'],
            'applied_at' => $row['applied_at'] === null ? null : (string) $row['applied_at'],
        ], $this->connection->query($sql)->fetchAll());
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
        );
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() === 1;
    }

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
        );
        $statement->execute(['table' => $table, 'column' => $column]);
        return (int) $statement->fetchColumn() === 1;
    }
}
