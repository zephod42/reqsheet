<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use DateInterval;
use DateTimeImmutable;
use Reqsheet\Teacher\TeacherPlanningService;
use Reqsheet\Teacher\TeacherWeek;
use Reqsheet\Timetable\TimetableSlot;
use Reqsheet\Timetable\ClassTone;
use Reqsheet\Timetable\TimetableValidationException;
use Reqsheet\Date\DateDisplay;

final class TeacherWeekPage
{
    public function __construct(
        private readonly TeacherPlanningService $service,
        private readonly int $organisationId,
        private readonly int $teacherId,
        private readonly DateTimeImmutable $today = new DateTimeImmutable('today'),
        private readonly ?array $user = null,
        ?string $dateFormat = null,
    ) {
        $this->dateDisplay = new DateDisplay($dateFormat ?? DateDisplay::DEFAULT_FORMAT);
    }

    private readonly DateDisplay $dateDisplay;

    /** @param array<string, mixed> $query @param array<string, mixed> $input */
    public function handle(string $method, array $query, array $input): string
    {
        $message = null;
        $date = $this->parseDate((string) ($query['date'] ?? $input['date'] ?? $this->today->format('Y-m-d')));
        if ($method === 'POST' && ($input['action'] ?? '') === 'duplicate') {
            try {
                if (!CsrfToken::valid($input['csrf_token'] ?? null)) {
                    throw new TimetableValidationException(['Your request expired. Please sign in and try again.']);
                }
                $week = $this->service->loadWeek($this->organisationId, $this->teacherId, $date);
                $visible = [];
                foreach ($week->days as $day) foreach ($day['occurrences'] as $occurrence) $visible[(int) $occurrence['id']] = true;
                $sourceId = (int) ($input['source_occurrence_id'] ?? 0);
                $targetId = (int) ($input['target_occurrence_id'] ?? 0);
                if (!isset($visible[$sourceId], $visible[$targetId])) {
                    throw new TimetableValidationException(['Both lessons must be visible in the displayed week.']);
                }
                $result = $this->service->duplicate(
                    $this->organisationId,
                    $this->teacherId,
                    $sourceId,
                    $targetId,
                    $week->start,
                    ($input['overwrite'] ?? '') === 'yes',
                    (string) ($input['target_revision'] ?? ''),
                );
                $confirmation = ($result['status'] ?? '') === 'confirmation_required';
                return $this->json(['ok' => !$confirmation] + $result, $confirmation ? 409 : 200);
            } catch (TimetableValidationException $exception) {
                return $this->json(['ok' => false, 'message' => implode(' ', $exception->errors())], 422);
            } catch (\Throwable) {
                return $this->json(['ok' => false, 'message' => 'The planning could not be copied. Please try again.'], 500);
            }
        }
        if ($method === 'POST') {
            try {
                if (!CsrfToken::valid($input['csrf_token'] ?? null)) {
                    throw new TimetableValidationException(['Your request expired. Please try again.']);
                }
                $this->service->save(
                    $this->organisationId,
                    $this->teacherId,
                    (int) ($input['occurrence_id'] ?? 0),
                    (string) ($input['lesson_outline'] ?? ''),
                    (string) ($input['requisitions'] ?? ''),
                    (string) ($input['risk_assessment'] ?? ''),
                );
                $message = 'Lesson planning saved.';
            } catch (TimetableValidationException $exception) {
                $message = implode(' ', $exception->errors());
            }
        }

        try {
            $week = $this->service->loadWeek($this->organisationId, $this->teacherId, $date);
        } catch (TimetableValidationException $exception) {
            return $this->error(implode(' ', $exception->errors()));
        }
        $edit = (int) ($query['edit'] ?? ($method === 'POST' ? 0 : 0));
        $editing = null;
        if ($edit > 0) {
            try { $editing = $this->service->occurrenceForEdit($this->organisationId, $this->teacherId, $edit); }
            catch (TimetableValidationException $exception) { $message = implode(' ', $exception->errors()); }
        }
        return $this->render($week, $message, $editing);
    }

    private function render(TeacherWeek $week, ?string $message, ?array $editing): string
    {
        $periods = [];
        foreach ($week->days as $day) foreach ($day['slots'] as $slot) $periods[$slot->sequenceNumber] = $slot;
        ksort($periods);
        $classTones = [];
        foreach ($week->days as $day) foreach ($day['occurrences'] as $occurrence) {
            $class = (string) $occurrence['snapshot_class_code'];
            $classTones[$class] ??= $this->classTone($class);
        }
        $currentWeekStart = $this->weekStart($this->today, (int) $week->start->format('N'));
        $relationship = $week->start->format('Y-m-d') === $currentWeekStart->format('Y-m-d') ? 'current'
            : ($week->start->format('Y-m-d') === $currentWeekStart->sub(new DateInterval('P7D'))->format('Y-m-d') ? 'previous'
            : ($week->start->format('Y-m-d') === $currentWeekStart->add(new DateInterval('P7D'))->format('Y-m-d') ? 'next' : null));
        $context = $relationship === 'previous' ? '<span class="week-context-label week-context-previous">Previous week</span>'
            : ($relationship === 'next' ? '<span class="week-context-label week-context-next">Next week</span>' : '');
        $body = '<header class="page-header"><a class="week-arrow" href="?date=' . $week->start->sub(new DateInterval('P7D'))->format('Y-m-d') . '" aria-label="Previous week">‹</a><div><h1>Week beginning ' . $this->e($this->weekDayLabel($week->start)) . '</h1>' . $context . '<a class="this-week' . ($relationship === 'current' ? ' selected-state week-context-current' : '') . '"' . ($relationship === 'current' ? ' aria-current="date"' : '') . ' href="?date=' . $this->today->format('Y-m-d') . '">This week</a></div><a class="week-arrow" href="?date=' . $week->start->add(new DateInterval('P7D'))->format('Y-m-d') . '" aria-label="Next week">›</a></header>';
        if ($message !== null) $body .= '<p class="message">' . $this->e($message) . '</p>';
        $identity = trim((string) ($this->user['staff_identifier'] ?? ''));
        $identityLabel = $identity !== '' ? $identity : 'Teacher';
        $body .= '<p class="teacher-identity">' . $this->e($identityLabel) . '</p>';
        $body .= '<div class="timetable-scroll"><table class="week-grid"><caption class="visually-hidden">Teacher timetable week</caption><thead><tr><th scope="col">Period</th>';
        foreach ($week->days as $day) {
            $date = $day['date'];
            $body .= '<th scope="col" class="day-label ' . ($date->format('Y-m-d') === $this->today->format('Y-m-d') ? 'today-heading' : '') . '"><a href="/teacher/day?date=' . $date->format('Y-m-d') . '">' . $this->e($date->format('D')) . '<br><small>' . $this->e($this->dateDisplay->format($date)) . '</small></a></th>';
        }
        $body .= '</tr></thead><tbody>';
        foreach ($periods as $sequence => $axisSlot) {
            $body .= '<tr' . ($axisSlot->isTeaching() ? '' : ' class="separator-row"') . '><th scope="row" class="period-label ' . ($axisSlot->isTeaching() ? '' : 'separator-axis') . '">' . $this->e($axisSlot->isTeaching() ? $this->periodLabel($axisSlot) : $this->separatorLabel($axisSlot)) . '</th>';
            foreach ($week->days as $day) {
                $date = $day['date'];
            $bySequence = [];
            foreach ($day['slots'] as $slot) $bySequence[$slot->sequenceNumber] = $slot;
            $byStart = [];
            foreach ($day['occurrences'] as $occurrence) $byStart[(int) $occurrence['snapshot_start_slot_id']] = $occurrence;
            $slot = $bySequence[$sequence] ?? null;
            $covered = false;
            foreach ($day['occurrences'] as $occurrence) {
                foreach ($bySequence as $candidateSequence => $candidateSlot) {
                    if ($candidateSlot->id === (int) $occurrence['snapshot_start_slot_id'] && $sequence > $candidateSequence && $sequence < $candidateSequence + max(1, (int) $occurrence['snapshot_duration_periods'])) {
                        $covered = true;
                        break 2;
                    }
                }
            }
            if ($covered) continue;
            if ($slot === null) { $body .= '<td></td>'; continue; }
            if (!$slot->isTeaching()) { $body .= '<td class="separator-cell" aria-label="' . $this->e($this->separatorLabel($slot)) . '"></td>'; continue; }
                $occurrence = $byStart[$slot->id] ?? null;
                if ($occurrence === null) { $body .= '<td class="empty-cell"></td>'; continue; }
                $duration = max(1, (int) $occurrence['snapshot_duration_periods']);
                $body .= '<td rowspan="' . $duration . '">' . $this->lessonBlock($occurrence, $date, $slot, $classTones[(string) $occurrence['snapshot_class_code']]) . '</td>';
            }
            $body .= '</tr>';
        }
        $body .= '</tbody></table></div>';
        $body .= $this->modal($editing);
        $body .= $this->duplicationDialogs($week);
        return PageLayout::render('Teacher week', $body . '<script src="/assets/teacher-week.js" defer></script>', $this->user);
    }

    /** @param array<string, mixed> $occurrence */
    private function lessonBlock(array $occurrence, DateTimeImmutable $date, TimetableSlot $slot, int $tone): string
    {
        $requirements = (string) ($occurrence['requirements_text'] ?? '');
        $outline = (string) ($occurrence['planning_notes'] ?? '');
        $risk = (string) ($occurrence['risk_assessment_text'] ?? '');
        $state = (string) ($occurrence['state'] ?? 'not_completed');
        $period = $this->periodLabel($slot) . ($occurrence['snapshot_duration_periods'] > 1 ? '–' . $occurrence['snapshot_duration_periods'] : '');
        $targetLabel = (string) $occurrence['snapshot_class_code'] . ' · ' . (string) $occurrence['snapshot_room_code'] . ' · ' . $this->dateDisplay->format($date) . ' · ' . $period;
        return '<a class="lesson-block class-tone-' . $tone . '" href="?date=' . $date->format('Y-m-d') . '&edit=' . (int) $occurrence['id'] . '" data-lesson-id="' . (int) $occurrence['id'] . '" data-class="' . $this->e((string) $occurrence['snapshot_class_code']) . '" data-class-id="' . (int) ($occurrence['class_id'] ?? 0) . '" data-room="' . $this->e((string) $occurrence['snapshot_room_code']) . '" data-date="' . $date->format('Y-m-d') . '" data-period="' . $this->e($period) . '" data-outline="' . $this->e($outline) . '" data-requisitions="' . $this->e($requirements) . '" data-risk="' . $this->e($risk) . '" data-state="' . $this->e($state) . '" data-planning-populated="' . (TeacherPlanningService::planningPopulated($occurrence) ? 'true' : 'false') . '" data-planning-revision="' . TeacherPlanningService::planningRevision($occurrence) . '" data-target-label="' . $this->e($targetLabel) . '"><span class="lesson-header"><strong>' . $this->e((string) $occurrence['snapshot_class_code']) . '</strong><span>' . $this->e((string) $occurrence['snapshot_room_code']) . '</span></span><span class="lesson-body">' . ($requirements === '' ? '<span class="muted">No requisitions entered</span>' : $this->e($requirements)) . '</span></a>';
    }

    /** @param array<string, mixed>|null $editing */
    private function modal(?array $editing): string
    {
        $open = $editing === null ? '' : ' open';
        $id = $editing === null ? 0 : (int) $editing['id'];
        $date = $editing === null ? '' : (string) $editing['lesson_date'];
        $class = $editing === null ? '' : (string) $editing['snapshot_class_code'];
        $classId = $editing === null ? 0 : (int) ($editing['class_id'] ?? 0);
        $context = $editing === null ? 'Select a lesson' : (string) $editing['snapshot_room_code'] . ' | ' . $date . ' · ' . (string) ($editing['slot_label'] ?? 'Teaching');
        $classLink = '<a id="lesson-class-link" href="/teacher/class' . ($classId > 0 ? '?class_id=' . $classId : '') . '"><strong>' . $this->e($class) . '</strong></a>';
        return '<dialog id="lesson-editor"' . $open . '><form method="post"><input type="hidden" name="csrf_token" value="' . $this->e(CsrfToken::value()) . '"><input type="hidden" name="occurrence_id" value="' . $id . '"><input type="hidden" name="date" value="' . $this->e($date) . '"><button type="button" class="close secondary" data-close>Close</button><p class="eyebrow">Lesson planning</p><h2>Edit lesson planning</h2><p class="lesson-context">' . $classLink . ' · ' . $this->e($context) . '</p><label class="field-outline">Lesson outline<textarea name="lesson_outline">' . $this->e((string) ($editing['planning_notes'] ?? '')) . '</textarea></label><label class="field-requisitions">Requisitions<textarea name="requisitions">' . $this->e((string) ($editing['requirements_text'] ?? '')) . '</textarea><span class="check-label"><input type="checkbox" data-nothing-required> Nothing required</span></label><label class="field-risk">Risk assessment<textarea name="risk_assessment">' . $this->e((string) ($editing['risk_assessment_text'] ?? '')) . '</textarea></label><div class="form-actions"><button type="submit">Save planning</button><button type="button" class="secondary" data-open-duplicate>Duplicate lesson…</button></div></form></dialog>';
    }

    private function duplicationDialogs(TeacherWeek $week): string
    {
        return '<dialog id="duplicate-picker" class="compact-dialog"><form method="dialog"><button type="button" class="close secondary" data-close>Close</button><p class="eyebrow">Copy planning</p><h2>Duplicate lesson</h2><p>Choose another lesson in this week.</p><label>Target lesson<select data-duplicate-target></select></label><div class="form-actions"><button type="button" data-copy-selected>Copy planning</button><button type="button" class="secondary" data-close>Cancel</button></div></form></dialog>'
            . '<dialog id="overwrite-dialog" class="compact-dialog"><form method="dialog"><p class="eyebrow">Confirm overwrite</p><h2>Overwrite target lesson?</h2><p data-overwrite-target></p><p>This action will overwrite the contents of the target lesson. Are you sure you want to proceed?</p><p class="notice warning" data-target-changed hidden>The target lesson changed after it was selected. Review the target and confirm again to overwrite its latest contents.</p><div class="form-actions"><button type="button" data-confirm-overwrite>Yes, overwrite</button><button type="button" class="secondary" data-close>Cancel</button></div></form></dialog>'
            . '<div class="copy-status" data-copy-status role="status" aria-live="polite"></div>'
            . '<span class="visually-hidden" data-week-date>' . $week->start->format('Y-m-d') . '</span>';
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) return $this->today;
        return $date;
    }
    private function weekStart(DateTimeImmutable $date, int $firstDay): DateTimeImmutable
    {
        $offset = ((int) $date->format('N') - $firstDay + 7) % 7;
        return $date->sub(new DateInterval('P' . $offset . 'D'));
    }

    private function weekDayLabel(DateTimeImmutable $date): string { return $date->format('l') . ' ' . $this->dateDisplay->format($date); }
    private function periodLabel(TimetableSlot $slot): string { return $slot->label ?: 'P' . $slot->teachingPeriodNumber; }
    private function separatorLabel(TimetableSlot $slot): string
    {
        $label = trim((string) ($slot->label ?? ''));
        if ($label !== '') return $label;
        return match ($slot->kind) { 'break' => 'Break', 'lunch' => 'Lunch', default => 'Other' };
    }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function error(string $message): string { return PageLayout::render('Teacher week', '<p class="notice error">' . $this->e($message) . '</p>', $this->user); }
    private function classTone(string $class): int { return ClassTone::forCode($class); }
    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status): string
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        return (string) json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
