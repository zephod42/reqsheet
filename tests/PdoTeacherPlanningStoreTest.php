<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use DateTimeImmutable;
use PDO;
use PDOStatement;
use Reqsheet\Teacher\PdoTeacherPlanningStore;

final class PdoTeacherPlanningStoreTest
{
    public static function run(): void
    {
        $pdo = new RecordingPdo();
        $store = new PdoTeacherPlanningStore($pdo);

        $store->effectiveVersion(7, new DateTimeImmutable('2026-09-18'));

        assertSameValue(['organisation_id' => 7, 'effective_from_date' => '2026-09-18', 'effective_to_date' => '2026-09-18'], $pdo->lastParameters, 'Effective-template lookup bound unexpected calendar parameters.');
        assertContainsValue('active_timetable_version_id', $pdo->lastSql, 'Teacher lookup did not use explicit activation.');
        assertContainsValue('effective_to', $pdo->lastSql, 'Teacher lookup did not respect timetable-version dates.');
        preg_match_all('/:([a-z_]+)/', $pdo->lastSql, $placeholders);
        assertSameValue(count($placeholders[1]), count(array_unique($placeholders[1])), 'Effective-template lookup reused a named placeholder that native MySQL PDO cannot bind.');

        $rangePdo = new PlanningRangePdo();
        $rangeStore = new PdoTeacherPlanningStore($rangePdo);
        $rangeStore->ensureOccurrencesForRange(7, new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'), 70, 700);
        assertSameValue(1, $rangePdo->executionsContaining('JOIN organisations o ON o.id = tv.organisation_id'), 'Range generation looked up the effective version once per calendar date.');
        assertSameValue(1, $rangePdo->executionsContaining('SELECT organisation_id FROM users'), 'Range generation repeated teacher-organisation validation for one teacher.');
        assertSameValue(4, count($rangePdo->occurrences), 'Range generation did not materialise only the selected recurring lesson weekdays.');
        $rangeStore->ensureOccurrencesForRange(7, new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'), 70, 700);
        assertSameValue(4, count($rangePdo->occurrences), 'Repeated range generation created duplicate occurrences.');
    }
}

final class PlanningRangePdo extends PDO
{
    /** @var list<string> */
    public array $executedSql = [];
    /** @var array<string, array<string,mixed>> */
    public array $occurrences = [];
    private bool $transaction = false;

    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new PlanningRangeStatement($this, $query);
    }

    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function commit(): bool { $this->transaction = false; return true; }
    public function rollBack(): bool { $this->transaction = false; return true; }
    public function inTransaction(): bool { return $this->transaction; }

    public function executionsContaining(string $fragment): int
    {
        return count(array_filter($this->executedSql, static fn (string $sql): bool => str_contains($sql, $fragment)));
    }
}

final class PlanningRangeStatement extends PDOStatement
{
    /** @var list<array<string,mixed>> */
    private array $rows = [];

    public function __construct(private readonly PlanningRangePdo $pdo, private readonly string $sql) {}

    public function execute(?array $params = null): bool
    {
        $params ??= [];
        $this->pdo->executedSql[] = $this->sql;
        if (str_contains($this->sql, 'SELECT rl.id, rl.timetable_version_id')) {
            $this->rows = [['id' => 900, 'timetable_version_id' => 10]];
        } elseif (str_contains($this->sql, 'JOIN organisations o ON o.id = tv.organisation_id')) {
            $this->rows = [['id' => 10, 'effective_from' => '2026-09-01', 'effective_to' => null]];
        } elseif (str_contains($this->sql, 'FROM timetable_versions') && str_contains($this->sql, 'WHERE id = :id')) {
            $this->rows = [['id' => 10, 'organisation_id' => 7, 'label' => 'Autumn', 'effective_from' => '2026-09-01', 'effective_to' => null, 'first_day_of_week' => 1]];
        } elseif (str_contains($this->sql, 'FROM timetable_slots WHERE timetable_version_id')) {
            $this->rows = [['id' => 100, 'timetable_version_id' => 10, 'day_of_week' => 1, 'sequence_number' => 1, 'kind' => 'teaching', 'teaching_period_number' => 1]];
        } elseif (str_contains($this->sql, 'FROM recurring_lessons WHERE timetable_version_id')) {
            $this->rows = [['id' => 900, 'timetable_version_id' => 10, 'teacher_user_id' => 70, 'day_of_week' => 1, 'start_slot_id' => 100, 'duration_periods' => 1, 'class_code' => '7SCI', 'room_code' => 'LAB']];
        } elseif (str_contains($this->sql, 'SELECT organisation_id FROM users')) {
            $this->rows = [['organisation_id' => 7]];
        } elseif (str_contains($this->sql, 'SELECT lesson_date, recurring_lesson_id')) {
            $this->rows = array_values(array_filter($this->pdo->occurrences, static fn (array $row): bool => $row['lesson_date'] >= $params['start_date'] && $row['lesson_date'] <= $params['end_date']));
        } elseif (str_contains($this->sql, 'INSERT INTO lesson_occurrences')) {
            $key = $params['lesson_date'] . '/' . $params['recurring_lesson_id'];
            $this->pdo->occurrences[$key] = ['lesson_date' => $params['lesson_date'], 'recurring_lesson_id' => $params['recurring_lesson_id']];
            $this->rows = [];
        } else {
            $this->rows = [];
        }
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetch(?int $mode = null, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->rows[0] ?? false; }
    public function fetchColumn(int $column = 0): mixed { $row = $this->rows[0] ?? null; return $row === null ? false : array_values($row)[$column]; }
}

final class RecordingPdo extends PDO
{
    public string $lastSql = '';
    public array $lastParameters = [];

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->lastSql = $query;
        return new RecordingStatement($this);
    }
}

final class RecordingStatement extends PDOStatement
{
    public function __construct(private readonly RecordingPdo $pdo)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->pdo->lastParameters = $params ?? [];
        return true;
    }

    public function fetch(?int $mode = null, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return false;
    }
}
