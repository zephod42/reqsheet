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
use Reqsheet\Timetable\BlankTimetableCsvExporter;
use Reqsheet\Timetable\TimetableCsvImportException;
use Reqsheet\Timetable\TimetableCsvImportPreviewService;
use Reqsheet\Timetable\TimetableCsvImportService;
use Reqsheet\Timetable\TimetableCsvParser;
use Reqsheet\Http\TeacherWeekPage;
use Reqsheet\Teacher\PdoTeacherPlanningStore;
use Reqsheet\Teacher\TeacherPlanningService;

const TEST_TABLES = [
    'requisitions',
    'technician_room_preferences',
    'onboarding_handoffs',
    'lesson_occurrences',
    'recurring_lessons',
    'timetable_slots',
    'timetable_versions',
    'organisation_classes',
    'organisation_rooms',
    'organisation_settings',
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

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach (TEST_TABLES as $table) $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

function insertId(PDO $pdo): int
{
    return (int) $pdo->lastInsertId();
}

/** @param array<string, array{0:string,1:string}> $assignments */
function integrationPopulateCsv(string $csv, array $assignments): string
{
    $input = fopen('php://temp', 'w+b');
    $output = fopen('php://temp', 'w+b');
    if ($input === false || $output === false) throw new RuntimeException('Unable to prepare integration CSV.');
    fwrite($input, $csv);
    rewind($input);
    while (($row = fgetcsv($input, null, ',', '"', '')) !== false) {
        if (($row[0] ?? '') !== 'Day') {
            $key = ($row[0] ?? '') . '|' . ($row[1] ?? '') . '|' . ($row[2] ?? '');
            if (isset($assignments[$key])) {
                $row[3] = $assignments[$key][0];
                $row[4] = $assignments[$key][1];
            }
        }
        fputcsv($output, $row, ',', '"', '', "\r\n");
    }
    rewind($output);
    $result = stream_get_contents($output);
    fclose($input);
    fclose($output);
    if ($result === false) throw new RuntimeException('Unable to read integration CSV.');
    return $result;
}

try {
    $environment = integrationEnvironment();
    $config = DatabaseConfig::fromEnvironment($environment, 'REQSHEET_TEST_DB_');
    $database = new Database($config);
    $pdo = $database->connection();

    cleanTestDatabase($pdo);
    try {
        $migrationCount = (new MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations'))->run();
        integrationAssert($migrationCount === 11, 'Expected all migrations to apply to the clean test database.');

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
                'staff_identifier' => 'INA',
                'operational_role' => 'teacher',
                'is_admin' => 1,
                'password_hash' => password_hash('integration-password', PASSWORD_DEFAULT),
                'account_state' => 'claimed',
            ],
        );
        $teacherB = $insert(
            "INSERT INTO users (organisation_id, display_name, staff_identifier, operational_role) VALUES (:organisation_id, :display_name, :staff_identifier, 'teacher')",
            ['organisation_id' => $organisationId, 'display_name' => 'Integration Teacher B', 'staff_identifier' => 'INB'],
        );
        $pdo->prepare('INSERT INTO organisation_settings (organisation_id, allow_double_periods) VALUES (:organisation_id, TRUE)')->execute(['organisation_id' => $organisationId]);
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
        $configurationStore->activateVersion($organisationId, $versionId);
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
        $insertLesson = static function (int $teacher, int $day, int $startSlot, int $duration, string $class, string $room) use ($configurationStore, $versionId): int {
            return $configurationStore->insertLesson($versionId, $teacher, $day, $startSlot, $duration, $class, $room);
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

        $pdo->prepare(
            "INSERT INTO requisitions (lesson_occurrence_id, state, requirements_text)
             SELECT id, 'requirements_entered', 'Preserved integration requisition'
             FROM lesson_occurrences WHERE recurring_lesson_id = :lesson_id LIMIT 1",
        )->execute(['lesson_id' => $singleLessonId]);
        $historicalOccurrences = (int) $pdo->query('SELECT COUNT(*) FROM lesson_occurrences')->fetchColumn();
        $historicalRequisitions = (int) $pdo->query('SELECT COUNT(*) FROM requisitions')->fetchColumn();

        $targetVersion = $configurationStore->insertVersion($organisationId, 'CSV import target', new DateTimeImmutable('2027-01-01'), null, 1);
        $targetP1 = $configurationStore->insertSlot($targetVersion, 1, 1, 'teaching', 1, 'P1', '09:00', '10:00');
        $targetP2 = $configurationStore->insertSlot($targetVersion, 1, 2, 'teaching', 2, 'P2', '10:00', '11:00');
        $configurationStore->insertSlot($targetVersion, 1, 3, 'break', null, 'Break', '11:00', '11:15');
        $targetP3 = $configurationStore->insertSlot($targetVersion, 1, 4, 'teaching', 3, 'P3', '11:15', '12:15');
        $targetTuesdayP1 = $configurationStore->insertSlot($targetVersion, 2, 1, 'teaching', 1, 'P1', '09:00', '10:00');
        $csvClassA = $configurationStore->createClass($organisationId, 'CSV-A');
        $csvClassB = $configurationStore->createClass($organisationId, 'CSV-B');
        $csvRoomA = $configurationStore->createRoom($organisationId, 'CSV-R1');
        $csvRoomB = $configurationStore->createRoom($organisationId, 'CSV-R2');
        $blankExport = (new BlankTimetableCsvExporter($configurationStore))->export($organisationId, $targetVersion);
        $completed = integrationPopulateCsv($blankExport->content, [
            'Monday|P1|CSV-R1' => ['CSV-A', 'INA'],
            'Monday|P2|CSV-R1' => ['CSV-A', 'INA'],
            'Monday|P3|CSV-R1' => ['CSV-A', 'INA'],
            'Tuesday|P1|CSV-R2' => ['CSV-B', 'INB'],
        ]);
        $parser = new TimetableCsvParser();
        $previewValidator = new TimetableCsvImportPreviewService($configurationStore);
        $preview = $previewValidator->preview($organisationId, $targetVersion, $parser->parse($completed), true);
        $draft = [
            'version_id' => $targetVersion,
            'assignments' => $preview->assignments,
            'occupied_periods' => $preview->occupiedPeriods,
            'free_slots' => $preview->freeSlots,
            'structure_identity' => $preview->structureIdentity,
        ];
        $importer = new TimetableCsvImportService($configurationStore, new BlankTimetableCsvExporter($configurationStore), $parser, $previewValidator);
        $imported = $importer->import($organisationId, $draft);
        integrationAssert($imported->lessonCount === 3 && $imported->occupiedPeriods === 4 && $imported->freeSlots === $blankExport->rowCount - 4, 'Atomic CSV import returned incorrect proposal counts.');
        $importedLessons = $configurationStore->lessonsForVersion($targetVersion);
        integrationAssert(count($importedLessons) === 3, 'Atomic CSV import inserted the wrong lesson count.');
        integrationAssert($importedLessons[0]->startSlotId === $targetP1 && $importedLessons[0]->durationPeriods === 2, 'Consecutive CSV periods were not persisted as one multi-period lesson.');
        integrationAssert($importedLessons[1]->startSlotId === $targetP3 && $importedLessons[1]->durationPeriods === 1, 'CSV lesson was incorrectly grouped across Break.');
        integrationAssert($importedLessons[2]->startSlotId === $targetTuesdayP1 && $importedLessons[2]->durationPeriods === 1, 'Single-period CSV lesson was not persisted.');
        integrationAssert($importedLessons[0]->teacherUserId === $teacherA && $importedLessons[0]->classId === $csvClassA && $importedLessons[0]->roomId === $csvRoomA, 'Imported teacher/class/room relationships were incorrect.');
        integrationAssert($importedLessons[2]->teacherUserId === $teacherB && $importedLessons[2]->classId === $csvClassB && $importedLessons[2]->roomId === $csvRoomB, 'Imported secondary relationships were incorrect.');
        integrationAssert($configurationStore->activeVersionId($organisationId) === $versionId, 'CSV import changed the active timetable.');
        integrationAssert((int) $pdo->query('SELECT COUNT(*) FROM lesson_occurrences')->fetchColumn() === $historicalOccurrences, 'CSV import changed historical occurrences.');
        integrationAssert((int) $pdo->query('SELECT COUNT(*) FROM requisitions')->fetchColumn() === $historicalRequisitions, 'CSV import changed historical requisitions.');

        $rollbackVersion = $configurationStore->insertVersion($organisationId, 'CSV rollback target', new DateTimeImmutable('2027-02-01'), null, 1);
        $rollbackSlot = $configurationStore->insertSlot($rollbackVersion, 1, 1, 'teaching', 1, 'P1', '09:00', '10:00');
        $configurationStore->beginCsvImport($organisationId, $rollbackVersion);
        $secondInsertFailed = false;
        try {
            $configurationStore->insertCsvImportLesson($organisationId, $rollbackVersion, $teacherA, 1, $rollbackSlot, 1, $csvClassA, $csvRoomA);
            $configurationStore->insertCsvImportLesson($organisationId, $rollbackVersion, $teacherB, 1, $rollbackSlot, 1, PHP_INT_MAX, $csvRoomB);
        } catch (RuntimeException) {
            $secondInsertFailed = true;
            $configurationStore->rollbackCsvImport();
        }
        if (!$secondInsertFailed) $configurationStore->rollbackCsvImport();
        integrationAssert($secondInsertFailed, 'Invalid second CSV insert unexpectedly succeeded.');
        integrationAssert($configurationStore->lessonsForVersion($rollbackVersion) === [], 'Database failure after one insert did not roll back the complete import.');

        $concurrentVersion = $configurationStore->insertVersion($organisationId, 'CSV concurrency target', new DateTimeImmutable('2027-03-01'), null, 1);
        $concurrentSlot = $configurationStore->insertSlot($concurrentVersion, 1, 1, 'teaching', 1, 'P1', '09:00', '10:00');
        $firstImporter = new PdoTimetableConfigurationStore($pdo);
        $secondPdo = (new Database($config))->connection();
        $secondPdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $secondImporter = new PdoTimetableConfigurationStore($secondPdo);
        $firstImporter->beginCsvImport($organisationId, $concurrentVersion);
        $lockPreventedSecond = false;
        try {
            $secondImporter->beginCsvImport($organisationId, $concurrentVersion);
        } catch (Throwable) {
            $lockPreventedSecond = true;
            $secondImporter->rollbackCsvImport();
        }
        integrationAssert($lockPreventedSecond, 'Concurrent import was not blocked by the timetable-version row lock.');
        $firstImporter->insertCsvImportLesson($organisationId, $concurrentVersion, $teacherA, 1, $concurrentSlot, 1, $csvClassA, $csvRoomA);
        $firstImporter->commitCsvImport();
        $secondRejectedPopulated = false;
        try {
            $secondImporter->beginCsvImport($organisationId, $concurrentVersion);
        } catch (TimetableCsvImportException) {
            $secondRejectedPopulated = true;
        }
        integrationAssert($secondRejectedPopulated, 'Second concurrent importer succeeded after the first import committed.');
        integrationAssert(count($configurationStore->lessonsForVersion($concurrentVersion)) === 1, 'Concurrent import handling duplicated lessons.');
    } finally {
        cleanTestDatabase($pdo);
    }

    fwrite(STDOUT, "Real MySQL integration checks passed.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Integration test failed: ' . $exception->getMessage() . PHP_EOL);
    exit($exception instanceof TimetableValidationException ? 2 : 1);
}
