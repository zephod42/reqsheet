<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use DateTimeImmutable;
use Reqsheet\Timetable\RecurringLesson;
use Reqsheet\Timetable\TimetableConfigurationStore;
use Reqsheet\Timetable\TimetableSlot;
use Reqsheet\Timetable\TimetableSlotService;
use Reqsheet\Timetable\TimetableValidationException;
use Reqsheet\Timetable\TimetableVersion;
use Reqsheet\Timetable\TimetableVersionService;
use Reqsheet\Timetable\RecurringLessonService;

final class TimetableConfigurationTest
{
    public static function run(): void
    {
        self::versions();
        self::slots();
        self::lessons();
    }

    private static function versions(): void
    {
        $store = self::store();
        $service = new TimetableVersionService($store);
        \assertSameValue(1, $service->create(1, 'Autumn', '2026-09-01', '2026-12-20'), 'Bounded version was not created.');
        \assertSameValue(2, $service->create(1, null, '2026-12-20', null), 'Adjacent open-ended version was not created.');
        \assertSameValue(3, $service->create(2, null, '2026-09-01', '2026-12-20'), 'Other organisation version was not created.');
        self::expectValidation(static fn () => $service->create(99, null, '2027-01-01', null));
        self::expectValidation(static fn () => $service->create(1, null, '2027-02-01', '2027-01-01'));
        self::expectValidation(static fn () => $service->create(1, null, '2026-12-19', '2027-01-01'));
        self::expectValidation(static fn () => $service->create(1, null, '', null));
        self::expectValidation(static fn () => $service->create(1, ' ', '2027-01-01', null));
    }

    private static function slots(): void
    {
        $store = self::storeWithVersion();
        $service = new TimetableSlotService($store);
        \assertSameValue(1, $service->create(1, 1, 1, 'teaching', 1, 'Period 1', '09:00', '10:00'), 'Teaching slot was not created.');
        \assertSameValue(2, $service->create(1, 1, 2, 'break', null, 'Break', '10:00:00', '10:15:00'), 'Break slot was not created.');
        \assertSameValue(3, $service->create(1, 2, 1, 'lunch', null, 'Lunch', '12:00', '13:00'), 'Lunch slot was not created.');
        \assertSameValue(4, $service->create(1, 2, 2, 'non_teaching', null, 'Assembly', '13:00', '14:00'), 'Non-teaching slot was not created.');
        self::expectSlotValidation($service, 1, 1, 1, 'teaching', null, 'Bad', '08:00', '09:00');
        self::expectSlotValidation($service, 1, 1, 3, 'break', 2, 'Bad', '10:15', '10:30');
        self::expectSlotValidation($service, 1, 1, 3, 'unknown', null, 'Bad', '10:15', '10:30');
        self::expectSlotValidation($service, 1, 1, 3, 'break', null, 'Bad', '10:30', '10:00');
        self::expectSlotValidation($service, 1, 1, 2, 'break', null, 'Duplicate', '10:15', '10:30');
        self::expectSlotValidation($service, 1, 1, 3, 'teaching', 1, 'Duplicate', '10:15', '10:30');
        self::expectSlotValidation($service, 1, 1, 3, 'break', null, 'Overlap', '09:30', '10:05');
        self::expectSlotValidation($service, 1, 1, 3, 'break', null, 'Out of order', '08:00', '08:30');
        self::expectSlotValidation($service, 1, 1, 3, 'break', null, ' ', '10:15', '10:30');
    }

    private static function lessons(): void
    {
        $store = self::storeWithVersion();
        self::configurationSlots($store);
        $service = new RecurringLessonService($store);
        \assertSameValue(1, $service->create(1, 10, 1, 101, 1, 'Y9', 'LAB-A'), 'Single lesson was not created.');
        \assertSameValue(2, $service->create(1, 11, 1, 101, 2, 'Y10', 'LAB-B'), 'Double lesson was not created.');
        \assertSameValue(3, $service->create(1, 11, 2, 201, 3, 'Y11', 'LAB-C'), 'Long lesson was not created.');
        \assertSameValue(4, $service->create(1, 10, 1, 102, 1, 'Y12', 'LAB-D'), 'Non-conflicting lesson was not created.');

        self::expectLessonValidation($service, 1, 20, 1, 101, 1, 'Y', 'LAB-E');
        self::expectLessonValidation($service, 99, 10, 1, 101, 1, 'Y', 'LAB-E');
        self::expectLessonValidation($service, 1, 10, 2, 101, 1, 'Y', 'LAB-E');
        self::expectLessonValidation($service, 1, 10, 1, 103, 1, 'Y', 'LAB-E');
        self::expectLessonValidation($service, 1, 10, 1, 102, 2, 'Y', 'LAB-E');
        self::expectLessonValidation($service, 1, 10, 1, 104, 2, 'Y', 'LAB-E');
        self::expectLessonValidation($service, 1, 10, 1, 106, 2, 'Y', 'LAB-E');
        self::expectLessonValidation($service, 1, 10, 1, 108, 2, 'Y', 'LAB-E');
        self::expectLessonValidation($service, 1, 10, 1, 101, 1, ' ', 'LAB-E');
        self::expectLessonValidation($service, 1, 10, 1, 101, 1, 'Y', ' ');
        self::expectLessonValidation($service, 1, 10, 1, 101, 1, 'Y', 'LAB-A');
        self::expectLessonValidation($service, 1, 11, 1, 101, 1, 'Y', 'LAB-A');
        self::expectLessonValidation($service, 1, 11, 1, 101, 1, 'Y9', 'LAB-Z');
    }

    private static function configurationSlots(ConfigurationStore $store): void
    {
        $store->slots = [
            new TimetableSlot(101, 1, 1, 1, 'teaching', 1, 'P1', '09:00:00', '10:00:00'),
            new TimetableSlot(102, 1, 1, 2, 'teaching', 2, 'P2', '10:00:00', '11:00:00'),
            new TimetableSlot(103, 1, 1, 3, 'break', null, 'Break', '11:00:00', '11:15:00'),
            new TimetableSlot(104, 1, 1, 4, 'teaching', 3, 'P3', '11:15:00', '12:15:00'),
            new TimetableSlot(105, 1, 1, 5, 'lunch', null, 'Lunch', '12:15:00', '13:00:00'),
            new TimetableSlot(106, 1, 1, 6, 'teaching', 4, 'P4', '13:00:00', '14:00:00'),
            new TimetableSlot(107, 1, 1, 7, 'non_teaching', null, 'Assembly', '14:00:00', '14:30:00'),
            new TimetableSlot(108, 1, 1, 8, 'teaching', 5, 'P5', '14:30:00', '15:30:00'),
            new TimetableSlot(201, 1, 2, 1, 'teaching', 1, 'P1', '09:00:00', '10:00:00'),
            new TimetableSlot(202, 1, 2, 2, 'teaching', 2, 'P2', '10:00:00', '11:00:00'),
            new TimetableSlot(203, 1, 2, 3, 'teaching', 3, 'P3', '11:00:00', '12:00:00'),
        ];
    }

    private static function store(): ConfigurationStore
    {
        return new ConfigurationStore();
    }

    private static function storeWithVersion(): ConfigurationStore
    {
        $store = self::store();
        $store->versions[1] = new TimetableVersion(1, 1, 'Test', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2027-01-01'));
        return $store;
    }

    private static function expectSlotValidation(TimetableSlotService $service, int $version, int $day, int $sequence, string $kind, ?int $period, string $label, string $start, string $end): void
    {
        self::expectValidation(static fn () => $service->create($version, $day, $sequence, $kind, $period, $label, $start, $end));
    }

    private static function expectLessonValidation(RecurringLessonService $service, int $version, int $teacher, int $day, int $slot, int $duration, string $class, string $room): void
    {
        self::expectValidation(static fn () => $service->create($version, $teacher, $day, $slot, $duration, $class, $room));
    }

    private static function expectValidation(callable $operation): void
    {
        try {
            $operation();
        } catch (TimetableValidationException) {
            return;
        }
        throw new \RuntimeException('Invalid timetable configuration was accepted.');
    }
}

final class ConfigurationStore implements TimetableConfigurationStore
{
    /** @var array<int, TimetableVersion> */
    public array $versions = [];
    /** @var list<TimetableSlot> */
    public array $slots = [];
    /** @var list<RecurringLesson> */
    public array $lessons = [];
    /** @var array<int, int> */
    public array $organisations = [1 => 1, 2 => 1];
    /** @var list<array{id:int,display_name:string,staff_identifier:?string,is_active:bool}> */
    public array $users = [
        ['id' => 10, 'display_name' => 'Teacher A', 'staff_identifier' => 'TA', 'is_active' => true],
        ['id' => 11, 'display_name' => 'Teacher B', 'staff_identifier' => 'TB', 'is_active' => true],
    ];
    /** @var array<int, int> */
    public array $occurrences = [];
    /** @var array<int, int> */
    public array $teachers = [10 => 1, 11 => 1, 20 => 2];
    private int $nextId = 1;

    public function organisationExists(int $organisationId): bool { return isset($this->organisations[$organisationId]); }
    public function findVersion(int $versionId): ?TimetableVersion { return $this->versions[$versionId] ?? null; }
    public function versionsForOrganisation(int $organisationId): array { return array_values(array_filter($this->versions, static fn (TimetableVersion $v): bool => $v->organisationId === $organisationId)); }
    public function usersForOrganisation(int $organisationId): array { return $this->users; }
    public function roomCodesForVersion(int $versionId): array { return array_values(array_unique(array_map(static fn (RecurringLesson $lesson): string => $lesson->roomCode, $this->lessonsForVersion($versionId)))); }
    public function insertVersion(int $organisationId, ?string $label, DateTimeImmutable $from, ?DateTimeImmutable $to): int { $id = $this->nextId++; $this->versions[$id] = new TimetableVersion($id, $organisationId, $label, $from, $to); return $id; }
    public function slotsForVersion(int $versionId): array { return array_values(array_filter($this->slots, static fn (TimetableSlot $s): bool => $s->timetableVersionId === $versionId)); }
    public function insertSlot(int $versionId, int $day, int $sequence, string $kind, ?int $period, string $label, string $start, string $end): int { $id = $this->nextId++; $this->slots[] = new TimetableSlot($id, $versionId, $day, $sequence, $kind, $period, $label, $start, $end); return $id; }
    public function findTeacherOrganisation(int $teacherUserId): ?int { return $this->teachers[$teacherUserId] ?? null; }
    public function lessonsForVersion(int $versionId): array { return array_values(array_filter($this->lessons, static fn (RecurringLesson $l): bool => $l->timetableVersionId === $versionId)); }
    public function findLesson(int $lessonId): ?RecurringLesson { foreach ($this->lessons as $lesson) { if ($lesson->id === $lessonId) return $lesson; } return null; }
    public function occurrenceCountForLesson(int $lessonId): int { return $this->occurrences[$lessonId] ?? 0; }
    public function insertLesson(int $versionId, int $teacher, int $day, int $slot, int $duration, string $class, string $room): int { $id = $this->nextId++; $this->lessons[] = new RecurringLesson($id, $versionId, $teacher, $day, $slot, $duration, $class, $room); return $id; }
    public function updateLesson(int $lessonId, int $teacher, int $day, int $slot, int $duration, string $class, string $room): void { foreach ($this->lessons as $index => $lesson) { if ($lesson->id === $lessonId) { $this->lessons[$index] = new RecurringLesson($lessonId, $lesson->timetableVersionId, $teacher, $day, $slot, $duration, $class, $room); } } }
    public function deleteLesson(int $lessonId): void { $this->lessons = array_values(array_filter($this->lessons, static fn (RecurringLesson $lesson): bool => $lesson->id !== $lessonId)); }
}
