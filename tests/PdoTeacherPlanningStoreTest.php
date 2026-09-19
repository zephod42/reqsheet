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

        assertSameValue(['organisation_id' => 7, 'lesson_date' => '2026-09-18'], $pdo->lastParameters, 'Effective-template lookup bound unexpected calendar parameters.');
        assertContainsValue('active_timetable_version_id', $pdo->lastSql, 'Teacher lookup did not use explicit activation.');
        assertContainsValue('effective_to', $pdo->lastSql, 'Teacher lookup did not respect timetable-version dates.');
    }
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
