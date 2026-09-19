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
        $start = $this->weekStart($date);
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

    /** @return array<string, mixed> */
    public function occurrenceForEdit(int $organisationId, int $teacherId, int $occurrenceId): array
    {
        $occurrence = $this->store->findOccurrenceForTeacher($organisationId, $teacherId, $occurrenceId);
        if ($occurrence === null) throw new TimetableValidationException(['Lesson occurrence was not found.']);
        return $occurrence;
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
