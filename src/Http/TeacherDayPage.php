<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use DateInterval;
use DateTimeImmutable;
use Reqsheet\Teacher\TeacherPlanningService;
use Reqsheet\Timetable\TimetableSlot;
use Reqsheet\Timetable\TimetableValidationException;

final class TeacherDayPage
{
    public function __construct(
        private readonly TeacherPlanningService $service,
        private readonly int $organisationId,
        private readonly int $teacherId,
        private readonly DateTimeImmutable $today = new DateTimeImmutable('today'),
        private readonly ?array $user = null,
    ) {
    }

    /** @param array<string, mixed> $query */
    public function handle(string $method, array $query = [], array $input = []): string
    {
        $date = $this->parseDate((string) ($query['date'] ?? $this->today->format('Y-m-d')));
        try {
            $week = $this->service->loadWeek($this->organisationId, $this->teacherId, $date);
        } catch (TimetableValidationException $exception) {
            return $this->error(implode(' ', $exception->errors()));
        }

        $selected = null;
        foreach ($week->days as $day) {
            if ($day['date']->format('Y-m-d') === $date->format('Y-m-d')) {
                $selected = $day;
                break;
            }
        }
        $body = '<header class="page-header"><div><p class="eyebrow">Teacher</p><h1>Day View</h1><h2 class="day-view-heading">' . $this->e($date->format('l j F Y')) . '</h2></div><div class="form-actions"><a class="button secondary" href="/teacher/day?date=' . $date->sub(new DateInterval('P1D'))->format('Y-m-d') . '">Previous day</a><a class="button secondary" href="/teacher/day?date=' . $this->today->format('Y-m-d') . '">Today</a><a class="button secondary" href="/teacher/day?date=' . $date->add(new DateInterval('P1D'))->format('Y-m-d') . '">Next day</a></div></header>';
        $body .= '<form class="day-picker" method="get"><label for="teacher-day-date">Select day</label><input id="teacher-day-date" type="date" name="date" value="' . $date->format('Y-m-d') . '"><button>Go</button></form>';
        $body .= '<p class="context">' . ($date->format('Y-m-d') === $this->today->format('Y-m-d') ? 'Today\'s lessons' : 'Lessons for ' . $this->e($date->format('l j F Y'))) . '</p>';
        if ($selected === null || $selected['occurrences'] === []) {
            $body .= '<p class="notice">No lessons are scheduled for this date.</p>';
            return PageLayout::render('Teacher day', $body, $this->user);
        }

        $slots = [];
        foreach ($selected['slots'] as $slot) $slots[$slot->id] = $slot;
        $occurrences = $selected['occurrences'];
        usort($occurrences, static fn (array $a, array $b): int => ((int) ($a['snapshot_start_slot_id'] ?? 0) <=> (int) ($b['snapshot_start_slot_id'] ?? 0)) ?: ((int) $a['id'] <=> (int) $b['id']));
        $body .= '<div class="teacher-day-table-wrap"><table class="teacher-day-table"><caption class="visually-hidden">Lessons for ' . $this->e($date->format('l j F Y')) . '</caption><thead><tr><th scope="col">Period</th><th scope="col">Room</th><th scope="col">Class code</th><th scope="col">Lesson outline</th><th scope="col">Requisitions</th><th scope="col">Risk assessment</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
        foreach ($occurrences as $occurrence) {
            $start = $slots[(int) ($occurrence['snapshot_start_slot_id'] ?? 0)] ?? null;
            if (!$start instanceof TimetableSlot) continue;
            $duration = max(1, (int) ($occurrence['snapshot_duration_periods'] ?? 1));
            $end = $this->slotAtSequence($selected['slots'], $start->sequenceNumber + $duration - 1) ?? $start;
            $period = $duration > 1 && $start->teachingPeriodNumber !== null && $end->teachingPeriodNumber !== null
                ? $start->teachingPeriodNumber . '–' . $end->teachingPeriodNumber
                : ($start->label ?: 'P' . ($start->teachingPeriodNumber ?? $start->sequenceNumber));
            $requirements = (string) ($occurrence['requirements_text'] ?? '');
            if ($requirements === '' && ($occurrence['state'] ?? '') === 'nothing_required') $requirements = 'Nothing required';
            $edit = '/teacher?date=' . $date->format('Y-m-d') . '&edit=' . (int) $occurrence['id'];
            $body .= '<tr><th scope="row">' . $this->e($period) . '</th><td>' . $this->e((string) $occurrence['snapshot_room_code']) . '</td><td><strong>' . $this->e((string) $occurrence['snapshot_class_code']) . '</strong></td><td>' . $this->textCell($occurrence['planning_notes'] ?? '') . '</td><td>' . $this->textCell($requirements, 'No requisitions entered') . '</td><td>' . $this->textCell($occurrence['risk_assessment_text'] ?? '') . '</td><td><a href="' . $edit . '">Edit</a></td></tr>';
        }
        $body .= '</tbody></table></div>';
        return PageLayout::render('Teacher day', $body, $this->user);
    }

    /** @param list<TimetableSlot> $slots */
    private function slotAtSequence(array $slots, int $sequence): ?TimetableSlot
    {
        foreach ($slots as $slot) if ($slot->sequenceNumber === $sequence) return $slot;
        return null;
    }

    private function textCell(mixed $value, string $empty = 'Not entered'): string
    {
        $text = trim((string) $value);
        return $text === '' ? '<span class="muted">' . $this->e($empty) . '</span>' : nl2br($this->e($text));
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $date : $this->today;
    }

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function error(string $message): string { return PageLayout::render('Teacher day', '<p class="notice error">' . $this->e($message) . '</p>', $this->user); }
}
