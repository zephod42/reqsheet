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

    /** @param array<string, mixed> $query @param array<string, mixed> $input */
    public function handle(string $method, array $query = [], array $input = []): string
    {
        $date = $this->parseDate((string) ($query['date'] ?? $this->today->format('Y-m-d')));
        $message = null;
        $editing = null;
        $form = null;
        if ($method === 'POST') {
            $date = $this->parseDate((string) ($input['date'] ?? $date->format('Y-m-d')));
            $section = (string) ($input['section'] ?? '');
            $form = [
                'section' => $section,
                'occurrence_id' => (int) ($input['occurrence_id'] ?? 0),
                'value' => (string) ($input['value'] ?? ''),
                'nothing_required' => ($input['nothing_required'] ?? '') === 'yes',
            ];
            try {
                if (!CsrfToken::valid($input['csrf_token'] ?? null)) {
                    throw new TimetableValidationException(['Your request expired. Please try again.']);
                }
                if (!in_array($section, ['outline', 'requisitions', 'risk'], true)) {
                    throw new TimetableValidationException(['Choose a valid lesson section.']);
                }
                $current = $this->service->occurrenceForEdit($this->organisationId, $this->teacherId, $form['occurrence_id']);
                if ((string) ($current['lesson_date'] ?? '') !== $date->format('Y-m-d')) {
                    throw new TimetableValidationException(['That lesson is not on the selected date.']);
                }
                $requisitions = $this->displayRequirements($current);
                $outline = (string) ($current['planning_notes'] ?? '');
                $risk = (string) ($current['risk_assessment_text'] ?? '');
                if ($section === 'outline') $outline = $form['value'];
                if ($section === 'requisitions') $requisitions = $form['nothing_required'] ? 'Nothing required' : $form['value'];
                if ($section === 'risk') $risk = $form['value'];
                $this->service->save($this->organisationId, $this->teacherId, $form['occurrence_id'], $outline, $requisitions, $risk);
                $message = 'Lesson section saved.';
                $form = null;
            } catch (TimetableValidationException $exception) {
                $message = implode(' ', $exception->errors());
                $editing = $form['occurrence_id'] > 0 ? $this->safeOccurrence($form['occurrence_id'], $date) : null;
            } catch (\Throwable) {
                $message = 'The lesson section could not be saved. Please try again.';
                $editing = $form['occurrence_id'] > 0 ? $this->safeOccurrence($form['occurrence_id'], $date) : null;
            }
        }
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
        if ($message !== null) $body .= '<p class="message">' . $this->e($message) . '</p>';
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
        $body .= '<div class="teacher-day-table-wrap"><table class="teacher-day-table"><caption class="visually-hidden">Lessons for ' . $this->e($date->format('l j F Y')) . '</caption><thead><tr><th scope="col">Period</th><th scope="col">Room</th><th scope="col">Class code</th><th scope="col">Lesson outline</th><th scope="col">Requisitions</th><th scope="col">Risk assessment</th></tr></thead><tbody>';
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
            $body .= '<tr><th scope="row">' . $this->e($period) . '</th><td>' . $this->e((string) $occurrence['snapshot_room_code']) . '</td><td><strong>' . $this->e((string) $occurrence['snapshot_class_code']) . '</strong></td><td>' . $this->sectionEditor($occurrence, 'outline', 'Lesson outline', (string) ($occurrence['planning_notes'] ?? ''), $editing, $form, $date) . '</td><td>' . $this->sectionEditor($occurrence, 'requisitions', 'Requisitions', $requirements, $editing, $form, $date, ($occurrence['state'] ?? '') === 'nothing_required') . '</td><td>' . $this->sectionEditor($occurrence, 'risk', 'Risk assessment', (string) ($occurrence['risk_assessment_text'] ?? ''), $editing, $form, $date) . '</td></tr>';
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

    private function displayRequirements(array $occurrence): string
    {
        $requirements = (string) ($occurrence['requirements_text'] ?? '');
        return $requirements === '' && ($occurrence['state'] ?? '') === 'nothing_required' ? 'Nothing required' : $requirements;
    }

    private function safeOccurrence(int $occurrenceId, DateTimeImmutable $date): ?array
    {
        try {
            $occurrence = $this->service->occurrenceForEdit($this->organisationId, $this->teacherId, $occurrenceId);
            return (string) ($occurrence['lesson_date'] ?? '') === $date->format('Y-m-d') ? $occurrence : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function sectionEditor(array $occurrence, string $section, string $label, string $savedValue, ?array $editing, ?array $form, DateTimeImmutable $date, bool $nothingRequired = false): string
    {
        $open = $editing !== null && (int) ($editing['id'] ?? 0) === (int) $occurrence['id'] && (($form['section'] ?? $section) === $section);
        $value = $open && $form !== null ? (string) $form['value'] : $savedValue;
        $checked = $open && $form !== null ? !empty($form['nothing_required']) : $nothingRequired;
        $formHtml = '<form method="post" class="inline-lesson-form"><input type="hidden" name="csrf_token" value="' . $this->e(CsrfToken::value()) . '"><input type="hidden" name="date" value="' . $date->format('Y-m-d') . '"><input type="hidden" name="occurrence_id" value="' . (int) $occurrence['id'] . '"><input type="hidden" name="section" value="' . $this->e($section) . '"><label><span class="visually-hidden">' . $this->e($label) . '</span><textarea name="value"' . ($section === 'requisitions' ? ' class="requisition-editor"' : '') . '>' . $this->e($value) . '</textarea></label>';
        if ($section === 'requisitions') $formHtml .= '<label class="check-label"><input type="checkbox" name="nothing_required" value="yes"' . ($checked ? ' checked' : '') . '> Nothing required</label>';
        $formHtml .= '<div class="form-actions"><button type="submit">Save</button><a class="button secondary" href="/teacher/day?date=' . $date->format('Y-m-d') . '">Cancel</a></div></form>';
        return '<details class="day-lesson-section"' . ($open ? ' open' : '') . '><summary><span>' . $this->e($label) . '</span><span class="section-edit-hint">Edit</span></summary><div class="day-lesson-value">' . $this->textCell($value, $section === 'requisitions' ? 'No requisitions entered' : 'Not entered') . '</div>' . $formHtml . '</details>';
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $date : $this->today;
    }

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function error(string $message): string { return PageLayout::render('Teacher day', '<p class="notice error">' . $this->e($message) . '</p>', $this->user); }
}
