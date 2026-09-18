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

        assertSameValue([
            'organisation_id' => 7,
            'effective_from_date' => '2026-09-18',
            'effective_to_date' => '2026-09-18',
        ], $pdo->lastParameters, 'Effective-version lookup did not bind both date comparisons independently.');
        assertContainsValue(':effective_from_date', $pdo->lastSql, 'Effective-version lookup lost its start-date parameter.');
        assertContainsValue(':effective_to_date', $pdo->lastSql, 'Effective-version lookup lost its end-date parameter.');
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
