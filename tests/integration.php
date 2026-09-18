<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Reqsheet\Database\Database;
use Reqsheet\Database\DatabaseConfig;
use Reqsheet\Database\MigrationRunner;
use Reqsheet\Timetable\PdoTimetableGenerationStore;
use Reqsheet\Timetable\PdoTimetableConfigurationStore;
use Reqsheet\Timetable\RecurringLessonService;
use Reqsheet\Timetable\TimetableSlotService;
use Reqsheet\Timetable\TimetableOccurrenceGenerator;
use Reqsheet\Timetable\TimetableVersionService;
use Reqsheet\Timetable\TimetableValidationException;
use Reqsheet\Http\TeacherWeekPage;
use Reqsheet\Teacher\PdoTeacherPlanningStore;
use Reqsheet\Teacher\TeacherPlanningService;

const TEST_TABLES = [
    'requisitions',
    'onboarding_handoffs',
    'organisation_classes',
    'lesson_occurrences',
    'recurring_lessons',
    'timetable_slots',
    'timetable_versions',
    'users',
    'organisations',
    'schema_migrations',
];

function integrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function integrationExpectValidation(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (TimetableValidationException) {
        return;
    }

    throw new RuntimeException($message);
}

function integrationEnvironment(): array
{
    $environment = getenv();
    if (!is_array($environment) || ($environment['REQSHEET_RUN_INTEGRATION'] ?? null) !== '1') {
        throw new RuntimeException('Refusing integration test: set REQSHEET_RUN_INTEGRATION=1 explicitly.');
    }

    foreach (['HOST', 'PORT', 'NAME', 'USER', 'PASSWORD'] as $suffix) {
        $key = 'REQSHEET_TEST_DB_' . $suffix;
        if (!array_key_exists($key, $environment)) {
            throw new RuntimeException('Refusing integration test: missing ' . $key . '.');
        }
    }

    if ($environment['REQSHEET_TEST_DB_NAME'] !== 'reqsheet_test') {
        throw new RuntimeException('Refusing integration test: REQSHEET_TEST_DB_NAME must be exactly reqsheet_test.');
    }

    if (in_array($environment['REQSHEET_TEST_DB_USER'], ['reqsheet_runtime', 'reqsheet_migrator'], true)) {
        throw new RuntimeException('Refusing integration test: use a dedicated test database user.');
    }

    return $environment;
}

function cleanTestDatabase(PDO $pdo): void
{
    $existing = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $existing[] = (string) $table;
    }
    $unexpected = array_values(array_diff($existing, TEST_TABLES));
    if ($unexpected !== []) {
        throw new RuntimeException('Refusing test database containing unexpected tables: ' . implode(', ', $unexpected));
    }

    foreach (TEST_TABLES as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
}

function insertId(PDO $pdo): int
{
    return (int) $pdo->lastInsertId();
}

try {
    $environment = integrationEnvironment();
    $config = DatabaseConfig::fromEnvironment($environment, 'REQSHEET_TEST_DB_');
    $database = new Database($config);
    $pdo = $database->connection();

    cleanTestDatabase($pdo);
    try {
        $migrationCount = (new MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations'))->run();
        integrationAssert($migrationCount === 7, 'Expected all migrations to apply to the clean test database.');

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (TEST_TABLES as $table) {
            integrationAssert(in_array($table, $tables, true), 'Missing migrated table: ' . $table);
        }

        $insert = static function (string $sql, array $parameters) use ($pdo): int {
            $statement = $pdo->prepare($sql);
            $statement->execute($parameters);
            return insertId($pdo);
        };

        $organisationId = $insert('INSERT INTO organisations (name, tenant_slug) VALUES (:name, :tenant_slug)', ['name' => 'Reqsheet Integration School', 'tenant_slug' => 'integration-school']);
        $teacherA = $insert(
            'INSERT INTO users
                (organisation_id, display_name, staff_identifier, operational_role, is_admin, password_hash, account_state)
             VALUES (:organisation_id, :display_name, :staff_identifier, :operational_role, :is_admin, :password_hash, :account_state)',
            [
                'organisation_id' => $organisationId,
                'display_name' => 'Integration Teacher A',
                'staff_identifier' => 'INT-A',
                'operational_role' => 'teacher',
                'is_admin' => 1,
                'password_hash' => password_hash('integration-password', PASSWORD_DEFAULT),
                'account_state' => 'claimed',
            ],
        );
        $teacherB = $insert(
            'INSERT INTO users (organisation_id, display_name, staff_identifier) VALUES (:organisation_id, :display_name, :staff_identifier)',
            ['organisation_id' => $organisationId, 'display_name' => 'Integration Teacher B', 'staff_identifier' => 'INT-B'],
        );
        $emptyTeacherPage = new TeacherWeekPage(
            new TeacherPlanningService(new PdoTeacherPlanningStore($pdo)),
            $organisationId,
            $teacherA,
            new DateTimeImmutable('2026-09-09'),
        );
        $emptyTeacherView = $emptyTeacherPage->handle('GET', [], []);
        integrationAssert(str_contains($emptyTeacherView, '<title>Teacher week · Reqsheet</title>'), 'Teacher page did not render before timetable setup.');
        integrationAssert(!str_contains($emptyTeacherView, 'Service unavailable'), 'Teacher page returned an unavailable state before timetable setup.');
        $configurationStore = new PdoTimetableConfigurationStore($pdo);
        $versionId = (new TimetableVersionService($configurationStore))->create(
            $organisationId,
            'Integration timetable',
            '2026-09-01',
            '2026-09-30',
        );
        $slotService = new TimetableSlotService($configurationStore);
        $slot = static function (int $day, int $sequence, string $kind, ?int $period) use ($slotService, $versionId): int {
            return $slotService->create(
                $versionId,
                $day,
                $sequence,
                $kind,
                $period,
                $period === null ? ucfirst($kind) : 'Period ' . $period,
                sprintf('%02d:00:00', 8 + $sequence),
                sprintf('%02d:00:00', 9 + $sequence),
            );
        };

        $mondayP1 = $slot(1, 1, 'teaching', 1);
        $mondayP2 = $slot(1, 2, 'teaching', 2);
        $break = $slot(1, 3, 'break', null);
        $mondayP3 = $slot(1, 4, 'teaching', 3);
        $nonTeaching = $slot(1, 5, 'non_teaching', null);
        $mondayP4 = $slot(1, 6, 'teaching', 4);
        $tuesdayP1 = $slot(2, 1, 'teaching', 1);
        $tuesdayP2 = $slot(2, 2, 'teaching', 2);

        $lessonService = new RecurringLessonService($configurationStore);
        $lesson = static function (int $teacher, int $day, int $startSlot, int $duration, string $class, string $room) use ($lessonService, $versionId): int {
            return $lessonService->create($versionId, $teacher, $day, $startSlot, $duration, $class, $room);
        };
        $insertLesson = static function (int $teacher, int $day, int $startSlot, int $duration, string $class, string $room) use ($insert, $versionId): int {
            return $insert(
                'INSERT INTO recurring_lessons
                    (timetable_version_id, teacher_user_id, day_of_week, start_slot_id, duration_periods, class_code, room_code)
                 VALUES (:version_id, :teacher, :day, :start_slot, :duration, :class_code, :room_code)',
                [
                    'version_id' => $versionId,
                    'teacher' => $teacher,
                    'day' => $day,
                    'start_slot' => $startSlot,
                    'duration' => $duration,
                    'class_code' => $class,
                    'room_code' => $room,
                ],
            );
        };

        $singleLessonId = $lesson($teacherA, 1, $mondayP1, 1, 'Y9-SCI-A', 'LAB-A');
        $doubleLessonId = $lesson($teacherA, 2, $tuesdayP1, 2, 'Y12-BIO-A', 'LAB-B');
        $generator = new TimetableOccurrenceGenerator(new PdoTimetableGenerationStore($pdo));
        $first = $generator->generate($organisationId, $versionId, '2026-09-07', '2026-09-08');
        integrationAssert($first->generated === 2 && $first->skippedExisting === 0, 'Valid lessons did not generate exactly two occurrences.');

        $selectOccurrence = $pdo->prepare(
            'SELECT recurring_lesson_id, timetable_version_id, snapshot_teacher_user_id, snapshot_class_code,
                    snapshot_room_code, snapshot_start_slot_id, snapshot_duration_periods
             FROM lesson_occurrences WHERE organisation_id = :organisation_id AND recurring_lesson_id = :lesson_id',
        );
        $selectOccurrence->execute(['organisation_id' => $organisationId, 'lesson_id' => $doubleLessonId]);
        $doubleOccurrence = $selectOccurrence->fetch();
        integrationAssert($doubleOccurrence !== false, 'Double-lesson occurrence was not created.');
        integrationAssert((int) $doubleOccurrence['timetable_version_id'] === $versionId, 'Occurrence version snapshot is incorrect.');
        integrationAssert((int) $doubleOccurrence['snapshot_teacher_user_id'] === $teacherA, 'Occurrence teacher snapshot is incorrect.');
        integrationAssert($doubleOccurrence['snapshot_class_code'] === 'Y12-BIO-A', 'Occurrence class snapshot is incorrect.');
        integrationAssert($doubleOccurrence['snapshot_room_code'] === 'LAB-B', 'Occurrence room snapshot is incorrect.');
        integrationAssert((int) $doubleOccurrence['snapshot_start_slot_id'] === $tuesdayP1, 'Occurrence start-slot snapshot is incorrect.');
        integrationAssert((int) $doubleOccurrence['snapshot_duration_periods'] === 2, 'Occurrence duration snapshot is incorrect.');

        $second = $generator->generate($organisationId, $versionId, '2026-09-07', '2026-09-08');
        integrationAssert($second->generated === 0 && $second->skippedExisting === 2, 'Repeated generation was not idempotent.');
        $selectOccurrence->execute(['organisation_id' => $organisationId, 'lesson_id' => $doubleLessonId]);
        integrationAssert($selectOccurrence->fetch() == $doubleOccurrence, 'Repeated generation altered an existing occurrence.');

        $invalidLessonId = $insertLesson($teacherA, 1, $mondayP2, 2, 'BREAK-TEST', 'LAB-C');
        integrationExpectValidation(
            static fn () => $generator->generate($organisationId, $versionId, '2026-09-07', '2026-09-07'),
            'A lesson crossing a break was accepted.',
        );
        $deleteLesson = $pdo->prepare('DELETE FROM recurring_lessons WHERE id = :id');
        $deleteLesson->execute(['id' => $invalidLessonId]);

        $nonTeachingLessonId = $insertLesson($teacherA, 1, $mondayP3, 2, 'NON-TEACHING-TEST', 'LAB-C');
        integrationExpectValidation(
            static fn () => $generator->generate($organisationId, $versionId, '2026-09-07', '2026-09-07'),
            'A lesson crossing a non-teaching slot was accepted.',
        );
        $deleteLesson->execute(['id' => $nonTeachingLessonId]);

        $conflictLessonId = $insertLesson($teacherA, 1, $mondayP1, 1, 'TEACHER-CONFLICT', 'LAB-C');
        integrationExpectValidation(
            static fn () => $generator->generate($organisationId, $versionId, '2026-09-07', '2026-09-07'),
            'A teacher conflict was accepted.',
        );
        $deleteLesson->execute(['id' => $conflictLessonId]);

        $roomConflictLessonId = $insertLesson($teacherB, 1, $mondayP1, 1, 'ROOM-CONFLICT', ' lab-a ');
        integrationExpectValidation(
            static fn () => $generator->generate($organisationId, $versionId, '2026-09-07', '2026-09-07'),
            'A room conflict was accepted.',
        );
        $deleteLesson->execute(['id' => $roomConflictLessonId]);
    } finally {
        cleanTestDatabase($pdo);
    }

    fwrite(STDOUT, "Real MySQL integration checks passed.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Integration test failed: ' . $exception->getMessage() . PHP_EOL);
    exit($exception instanceof TimetableValidationException ? 2 : 1);
}
