<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use DateTimeImmutable;
use Reqsheet\Timetable\GenerationResult;
use Reqsheet\Timetable\RecurringLesson;
use Reqsheet\Timetable\TimetableGenerationStore;
use Reqsheet\Timetable\TimetableOccurrenceGenerator;
use Reqsheet\Timetable\TimetableSlot;
use Reqsheet\Timetable\TimetableValidationException;
use Reqsheet\Timetable\TimetableVersion;

final class TimetableGenerationTest
{
    public static function run(): void
    {
        self::validDurations();
        self::rejectsInvalidSpans();
        self::rejectsInvalidRelationships();
        self::rejectsConflicts();
        self::acceptsNonConflictingLessons();
        self::generatesWithinDatesAndWeekdays();
        self::handlesOpenEndedVersions();
        self::isIdempotentAndPreservesExistingSnapshots();
        self::handlesEmptyAndInvalidRanges();
    }

    private static function validDurations(): void
    {
        self::expectResult(self::generator(self::lesson(1, 1, 101, 1)), '2026-09-07', '2026-09-07', 1, 0);
        self::expectResult(self::generator(self::lesson(1, 1, 101, 2)), '2026-09-07', '2026-09-07', 1, 0);
        self::expectResult(self::generator(self::lesson(1, 2, 201, 3)), '2026-09-01', '2026-09-01', 1, 0);
    }

    private static function rejectsInvalidSpans(): void
    {
        foreach ([
            self::lesson(1, 1, 102, 2),
            self::lesson(1, 1, 104, 2),
            self::lesson(1, 1, 106, 2),
            self::lesson(1, 1, 107, 1),
            self::lesson(1, 1, 108, 2),
        ] as $lesson) {
            self::expectValidation(self::generator($lesson), '2026-09-07', '2026-09-07');
        }
    }

    private static function rejectsInvalidRelationships(): void
    {
        self::expectValidationForOrganisation(
            self::generator(self::lesson(1, 1, 101, 1)),
            2,
            10,
            '2026-09-07',
            '2026-09-07',
        );
        $wrongVersionSlot = new TimetableSlot(301, 99, 1, 1, 'teaching', 1);
        self::expectValidation(
            self::generator(self::lesson(1, 1, 301, 1), [$wrongVersionSlot]),
            '2026-09-07',
            '2026-09-07',
        );
        self::expectValidation(
            self::generator(self::lesson(1, 1, 201, 1)),
            '2026-09-07',
            '2026-09-07',
        );
        self::expectValidation(
            self::generator(self::lesson(1, 1, 101, 1, 502)),
            '2026-09-07',
            '2026-09-07',
        );
        self::expectValidation(
            self::generator(self::lesson(1, 1, 101, 1, 999)),
            '2026-09-07',
            '2026-09-07',
        );
    }

    private static function rejectsConflicts(): void
    {
        self::expectValidation(
            self::generator([
                self::lesson(1, 1, 101, 2),
                self::lesson(2, 1, 102, 1),
            ]),
            '2026-09-07',
            '2026-09-07',
        );
        self::expectValidation(
            self::generator([
                self::lesson(1, 1, 101, 1, 501, 'LAB-A'),
                self::lesson(2, 1, 102, 1, 502, ' lab-a '),
            ]),
            '2026-09-07',
            '2026-09-07',
        );
    }

    private static function acceptsNonConflictingLessons(): void
    {
        self::expectResult(self::generator([
            self::lesson(1, 1, 101, 1),
            self::lesson(2, 1, 102, 1),
        ]), '2026-09-07', '2026-09-07', 2, 0);
        self::expectResult(self::generator([
                self::lesson(1, 1, 101, 1, 501, 'LAB-A'),
                self::lesson(2, 1, 102, 1, 503, 'LAB-A'),
        ]), '2026-09-07', '2026-09-07', 2, 0);
        self::expectResult(self::generator([
            self::lesson(1, 1, 101, 1, 501, 'LAB-A'),
            self::lesson(2, 2, 201, 1, 503, 'LAB-A'),
        ]), '2026-09-08', '2026-09-08', 1, 0);
    }

    private static function generatesWithinDatesAndWeekdays(): void
    {
        self::expectResult(self::generator(self::lesson(1, 1, 101, 1)), '2026-09-07', '2026-09-08', 1, 0);

        $store = self::store(self::lesson(1, 1, 101, 1));
        $store->version = new TimetableVersion(10, 1, null, self::date('2026-09-01'), self::date('2026-09-10'));
        $result = (new TimetableOccurrenceGenerator($store))->generate(1, 10, '2026-08-31', '2026-09-14');
        \assertSameValue(1, $result->generated, 'Generation was not clipped to effective dates.');
        \assertSameValue('2026-09-07/1', array_key_first($store->occurrences), 'Occurrence date was incorrect.');
    }

    private static function handlesOpenEndedVersions(): void
    {
        $store = self::store(self::lesson(1, 1, 101, 1));
        $store->version = new TimetableVersion(10, 1, null, self::date('2026-09-01'), null);
        $result = (new TimetableOccurrenceGenerator($store))->generate(1, 10, '2026-09-07', '2026-09-14');
        \assertSameValue(2, $result->generated, 'Open-ended version was not handled.');
    }

    private static function isIdempotentAndPreservesExistingSnapshots(): void
    {
        $store = self::store(self::lesson(1, 1, 101, 1, 501, 'LAB-A', 'OLD-CLASS'));
        $generator = new TimetableOccurrenceGenerator($store);
        $first = $generator->generate(1, 10, '2026-09-07', '2026-09-07');
        \assertSameValue(1, $first->generated, 'Initial occurrence was not generated.');
        $store->lessons[0] = self::lesson(1, 1, 101, 1, 501, 'LAB-B', 'NEW-CLASS');
        $second = $generator->generate(1, 10, '2026-09-07', '2026-09-07');
        \assertSameValue(0, $second->generated, 'Repeated generation created a duplicate.');
        \assertSameValue(1, $second->skippedExisting, 'Existing occurrence was not counted as skipped.');
        \assertSameValue('OLD-CLASS', $store->occurrences['2026-09-07/1']['class_code'], 'Existing snapshot was rewritten.');
    }

    private static function handlesEmptyAndInvalidRanges(): void
    {
        self::expectResult(self::generator([]), '2026-09-07', '2026-09-07', 0, 0);
        self::expectValidation(self::generator(self::lesson(1, 1, 101, 1)), '2026-09-08', '2026-09-07');
        self::expectValidation(self::generator(self::lesson(1, 1, 101, 1)), 'not-a-date', '2026-09-07');
    }

    private static function generator(array|RecurringLesson $lessons, array $extraSlots = []): TimetableOccurrenceGenerator
    {
        return new TimetableOccurrenceGenerator(self::store($lessons, $extraSlots));
    }

    private static function store(array|RecurringLesson $lessons, array $extraSlots = []): InMemoryTimetableStore
    {
        $store = new InMemoryTimetableStore();
        $store->slots = array_merge(self::baseSlots(), $extraSlots);
        $store->lessons = $lessons instanceof RecurringLesson ? [$lessons] : $lessons;
        return $store;
    }

    private static function lesson(int $id, int $day, int $startSlot, int $duration, int $teacher = 501, string $room = 'LAB-A', string $class = 'Y9-SCI-A'): RecurringLesson
    {
        return new RecurringLesson($id, 10, $teacher, $day, $startSlot, $duration, $class, $room);
    }

    /** @return list<TimetableSlot> */
    private static function baseSlots(): array
    {
        return [
            new TimetableSlot(101, 10, 1, 1, 'teaching', 1),
            new TimetableSlot(102, 10, 1, 2, 'teaching', 2),
            new TimetableSlot(103, 10, 1, 3, 'break', null),
            new TimetableSlot(104, 10, 1, 4, 'teaching', 3),
            new TimetableSlot(105, 10, 1, 5, 'lunch', null),
            new TimetableSlot(106, 10, 1, 6, 'teaching', 4),
            new TimetableSlot(107, 10, 1, 7, 'non_teaching', null),
            new TimetableSlot(108, 10, 1, 8, 'teaching', 5),
            new TimetableSlot(201, 10, 2, 1, 'teaching', 1),
            new TimetableSlot(202, 10, 2, 2, 'teaching', 2),
            new TimetableSlot(203, 10, 2, 3, 'teaching', 3),
        ];
    }

    private static function expectResult(TimetableOccurrenceGenerator $generator, string $start, string $end, int $generated, int $skipped): void
    {
        $result = $generator->generate(1, 10, $start, $end);
        \assertSameValue($generated, $result->generated, 'Unexpected generated occurrence count.');
        \assertSameValue($skipped, $result->skippedExisting, 'Unexpected skipped occurrence count.');
    }

    private static function expectValidation(TimetableOccurrenceGenerator $generator, string $start, string $end): void
    {
        \assertThrows(
            static fn (): GenerationResult => $generator->generate(1, 10, $start, $end),
            'Invalid timetable input was accepted.',
        );
    }

    private static function expectValidationForOrganisation(TimetableOccurrenceGenerator $generator, int $organisationId, int $versionId, string $start, string $end, string $message = 'Invalid organisation/version input was accepted.'): void
    {
        \assertThrows(
            static fn (): GenerationResult => $generator->generate($organisationId, $versionId, $start, $end),
            $message,
        );
    }

    private static function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }
}

final class InMemoryTimetableStore implements TimetableGenerationStore
{
    public TimetableVersion $version;
    /** @var list<TimetableSlot> */
    public array $slots = [];
    /** @var list<RecurringLesson> */
    public array $lessons = [];
    /** @var array<int, int> */
    public array $teacherOrganisations = [501 => 1, 502 => 2, 503 => 1];
    /** @var array<string, array<string, mixed>> */
    public array $occurrences = [];
    private ?array $transactionSnapshot = null;

    public function __construct()
    {
        $this->version = new TimetableVersion(10, 1, null, new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));
    }

    public function findVersion(int $organisationId, int $versionId): ?TimetableVersion
    {
        return $organisationId === $this->version->organisationId && $versionId === $this->version->id ? $this->version : null;
    }

    public function findTeacherOrganisation(int $teacherUserId): ?int
    {
        return $this->teacherOrganisations[$teacherUserId] ?? null;
    }

    public function findSlot(int $slotId): ?TimetableSlot
    {
        foreach ($this->slots as $slot) {
            if ($slot->id === $slotId) {
                return $slot;
            }
        }
        return null;
    }

    public function slotsForVersion(int $versionId): array
    {
        return array_values(array_filter($this->slots, static fn (TimetableSlot $slot): bool => $slot->timetableVersionId === $versionId));
    }

    public function lessonsForVersion(int $versionId): array
    {
        return array_values(array_filter($this->lessons, static fn (RecurringLesson $lesson): bool => $lesson->timetableVersionId === $versionId));
    }

    public function existingOccurrenceKeys(int $organisationId, string $startDate, string $endDate): array
    {
        $keys = [];
        foreach (array_keys($this->occurrences) as $key) {
            [$date] = explode('/', $key, 2);
            if ($date >= $startDate && $date <= $endDate) {
                $keys[$key] = true;
            }
        }
        return $keys;
    }

    public function begin(): void
    {
        $this->transactionSnapshot = $this->occurrences;
    }

    public function commit(): void
    {
        $this->transactionSnapshot = null;
    }

    public function rollBack(): void
    {
        if ($this->transactionSnapshot !== null) {
            $this->occurrences = $this->transactionSnapshot;
            $this->transactionSnapshot = null;
        }
    }

    public function insertOccurrence(int $organisationId, RecurringLesson $lesson, TimetableSlot $startSlot, TimetableVersion $version, string $lessonDate): void
    {
        $this->occurrences[$lessonDate . '/' . $lesson->id] = [
            'teacher_user_id' => $lesson->teacherUserId,
            'class_code' => $lesson->classCode,
            'room_code' => $lesson->roomCode,
            'start_slot_id' => $startSlot->id,
            'duration_periods' => $lesson->durationPeriods,
            'version_id' => $version->id,
        ];
    }
}
