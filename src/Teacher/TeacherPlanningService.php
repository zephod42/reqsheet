<?php

declare(strict_types=1);

namespace Reqsheet\Teacher;

use DateInterval;
use DateTimeImmutable;
use Reqsheet\Timetable\TimetableValidationException;

final class TeacherPlanningService
{
    public function __construct(private readonly TeacherPlanningStore $store, private readonly int $firstDayOfWeek = 1)
    {
        if ($firstDayOfWeek < 1 || $firstDayOfWeek > 7) {
            throw new \InvalidArgumentException('First day of week must be between 1 and 7.');
        }
    }

    public function weekStart(DateTimeImmutable $date): DateTimeImmutable
    {
        $offset = ((int) $date->format('N') - $this->firstDayOfWeek + 7) % 7;
        return $date->sub(new DateInterval('P' . $offset . 'D'));
    }

    public function loadWeek(int $organisationId, int $teacherId, DateTimeImmutable $date): TeacherWeek
    {
        if (!$this->store->teacherBelongsToOrganisation($teacherId, $organisationId)) {
            throw new TimetableValidationException(['Teacher does not belong to the requested organisation.']);
        }
        $firstDay = $this->store->activeFirstDayOfWeek($organisationId);
        $start = $firstDay >= 1 && $firstDay <= 7 ? $date->sub(new DateInterval('P' . (((int) $date->format('N') - $firstDay + 7) % 7) . 'D')) : $this->weekStart($date);
        $this->store->ensureOccurrencesForWeek($organisationId, $start, $start->add(new DateInterval('P6D')));
        $days = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $day = $start->add(new DateInterval('P' . $offset . 'D'));
            $version = $this->store->effectiveVersion($organisationId, $day);
            $slots = $version === null ? [] : array_values(array_filter(
                $this->store->slotsForVersion($version->id),
                static fn ($slot): bool => $slot->dayOfWeek === (int) $day->format('N'),
            ));
            if ($slots === []) continue;
            $days[] = [
                'date' => $day,
                'version' => $version,
                'slots' => $slots,
                'occurrences' => $this->store->occurrencesForTeacherDate($organisationId, $teacherId, $day),
            ];
        }
        return new TeacherWeek($start, $start->add(new DateInterval('P6D')), $days);
    }

    /** @return list<array{id:int,code:string}> */
    public function classes(int $organisationId, int $teacherId): array
    {
        $this->assertTeacher($organisationId, $teacherId);
        return $this->store->classesForTeacher($organisationId, $teacherId);
    }

    /** @return array{classes:list<array{id:int,code:string}>,selected:?array{id:int,code:string},previous:list<array<string,mixed>>,upcoming:list<array<string,mixed>>} */
    public function loadClass(int $organisationId, int $teacherId, int $classId, DateTimeImmutable $today): array
    {
        $this->assertTeacher($organisationId, $teacherId);
        $classes = $this->store->classesForTeacher($organisationId, $teacherId);
        $selected = null;
        foreach ($classes as $class) if ($class['id'] === $classId) { $selected = $class; break; }
        if ($selected === null) throw new TimetableValidationException(['Class is not available for this teacher.']);

        // The window is deliberately bounded. Occurrences are the existing dated
        // snapshots; generation only makes the established timetable model
        // available for this finite view window.
        $pastStart = $today->sub(new DateInterval('P365D'));
        $futureEnd = $today->add(new DateInterval('P365D'));
        $this->store->ensureOccurrencesForRange($organisationId, $pastStart, $futureEnd);
        // Retrieval remains bounded by the result limits; the wide date bounds
        // allow already-existing historical snapshots outside the generation
        // window to remain visible.
        $previous = $this->store->occurrencesForTeacherClass($organisationId, $teacherId, $classId, new DateTimeImmutable('1000-01-01'), $today->sub(new DateInterval('P1D')), 3, true);
        $upcoming = $this->store->occurrencesForTeacherClass($organisationId, $teacherId, $classId, $today, new DateTimeImmutable('9999-12-31'), 21);
        usort($previous, self::occurrenceOrder(...));
        usort($upcoming, self::occurrenceOrder(...));
        return ['classes' => $classes, 'selected' => $selected, 'previous' => $previous, 'upcoming' => $upcoming];
    }

    /** @return array<string, mixed> */
    public function occurrenceForEdit(int $organisationId, int $teacherId, int $occurrenceId): array
    {
        $occurrence = $this->store->findOccurrenceForTeacher($organisationId, $teacherId, $occurrenceId);
        if ($occurrence === null) throw new TimetableValidationException(['Lesson occurrence was not found.']);
        return $occurrence;
    }

    private function assertTeacher(int $organisationId, int $teacherId): void
    {
        if (!$this->store->teacherBelongsToOrganisation($teacherId, $organisationId)) throw new TimetableValidationException(['Teacher does not belong to the requested organisation.']);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function occurrenceOrder(array $left, array $right): int
    {
        return [((string) $left['lesson_date']), ((int) ($left['teaching_period_number'] ?? 0)), ((int) $left['id'])]
            <=> [((string) $right['lesson_date']), ((int) ($right['teaching_period_number'] ?? 0)), ((int) $right['id'])];
    }

    public function save(
        int $organisationId,
        int $teacherId,
        int $occurrenceId,
        string $lessonOutline,
        string $requisitions,
        string $riskAssessment,
    ): void {
        $occurrence = $this->occurrenceForEdit($organisationId, $teacherId, $occurrenceId);
        $lessonOutline = trim($lessonOutline);
        $requisitions = trim($requisitions);
        $riskAssessment = trim($riskAssessment);
        $existingState = is_string($occurrence['state'] ?? null) ? $occurrence['state'] : null;
        $state = $requisitions === 'Nothing required'
            ? 'nothing_required'
            : ($requisitions !== ''
            ? 'requirements_entered'
            : ($existingState === 'nothing_required' ? 'nothing_required' : 'not_completed'));
        $this->store->savePlanning($occurrenceId, $state, $lessonOutline, $requisitions, $riskAssessment);
    }
}
