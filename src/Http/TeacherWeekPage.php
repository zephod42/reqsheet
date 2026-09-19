<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use DateInterval;
use DateTimeImmutable;
use Reqsheet\Teacher\TeacherPlanningService;
use Reqsheet\Teacher\TeacherWeek;
use Reqsheet\Timetable\TimetableSlot;
use Reqsheet\Timetable\TimetableValidationException;

final class TeacherWeekPage
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
    public function handle(string $method, array $query, array $input): string
    {
        $message = null;
        if ($method === 'POST') {
            try {
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

        $date = $this->parseDate((string) ($query['date'] ?? $input['date'] ?? $this->today->format('Y-m-d')));
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
        $isCurrentWeek = $this->today >= $week->start && $this->today <= $week->end;
        $body = '<header class="page-header"><a class="week-arrow" href="?date=' . $week->start->sub(new DateInterval('P7D'))->format('Y-m-d') . '" aria-label="Previous week">‹</a><div><h1>Week beginning ' . $this->e($this->weekDayLabel($week->start)) . '</h1><a class="this-week' . ($isCurrentWeek ? ' selected-state' : '') . '"' . ($isCurrentWeek ? ' aria-current="date"' : '') . ' href="?date=' . $this->today->format('Y-m-d') . '">This week</a></div><a class="week-arrow" href="?date=' . $week->start->add(new DateInterval('P7D'))->format('Y-m-d') . '" aria-label="Next week">›</a></header>';
        if ($message !== null) $body .= '<p class="message">' . $this->e($message) . '</p>';
        $identity = trim((string) ($this->user['display_name'] ?? ''));
        $initials = trim((string) ($this->user['staff_identifier'] ?? ''));
        $identityLabel = $identity !== '' ? $identity . ($initials !== '' ? ' (' . $initials . ')' : '') : ($initials !== '' ? $initials : 'Teacher');
        $body .= '<p class="teacher-identity">' . $this->e($identityLabel) . '</p>';
        $body .= '<div class="timetable-scroll"><table class="week-grid"><caption class="visually-hidden">Teacher timetable week</caption><thead><tr><th scope="col">Period</th>';
        foreach ($week->days as $day) {
            $date = $day['date'];
            $body .= '<th scope="col" class="day-label ' . ($date->format('Y-m-d') === $this->today->format('Y-m-d') ? 'today-heading' : '') . '"><a href="/teacher/day?date=' . $date->format('Y-m-d') . '">' . $this->e($date->format('D')) . '<br><small>' . $date->format('j M') . '</small></a></th>';
        }
        $body .= '</tr></thead><tbody>';
        foreach ($periods as $sequence => $axisSlot) {
            $body .= '<tr><th scope="row" class="period-label ' . ($axisSlot->isTeaching() ? '' : 'separator-axis') . '">' . $this->e($axisSlot->isTeaching() ? $this->periodLabel($axisSlot) : $this->separatorLabel($axisSlot)) . '</th>';
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
        return PageLayout::render('Teacher week', $body . '<script>' . $this->script() . '</script>', $this->user);
    }

    /** @param array<string, mixed> $occurrence */
    private function lessonBlock(array $occurrence, DateTimeImmutable $date, TimetableSlot $slot, int $tone): string
    {
        $requirements = (string) ($occurrence['requirements_text'] ?? '');
        $outline = (string) ($occurrence['planning_notes'] ?? '');
        $risk = (string) ($occurrence['risk_assessment_text'] ?? '');
        $period = $this->periodLabel($slot) . ($occurrence['snapshot_duration_periods'] > 1 ? '–' . $occurrence['snapshot_duration_periods'] : '');
        return '<a class="lesson-block class-tone-' . $tone . '" href="?date=' . $date->format('Y-m-d') . '&edit=' . (int) $occurrence['id'] . '" data-lesson-id="' . (int) $occurrence['id'] . '" data-class="' . $this->e((string) $occurrence['snapshot_class_code']) . '" data-room="' . $this->e((string) $occurrence['snapshot_room_code']) . '" data-date="' . $date->format('Y-m-d') . '" data-period="' . $this->e($period) . '" data-outline="' . $this->e($outline) . '" data-requisitions="' . $this->e($requirements) . '" data-risk="' . $this->e($risk) . '"><span class="lesson-header"><strong>' . $this->e((string) $occurrence['snapshot_class_code']) . '</strong><span>' . $this->e((string) $occurrence['snapshot_room_code']) . '</span></span><span class="lesson-body">' . ($requirements === '' ? '<span class="muted">No requisitions entered</span>' : $this->e($requirements)) . '</span></a>';
    }

    /** @param array<string, mixed>|null $editing */
    private function modal(?array $editing): string
    {
        $open = $editing === null ? '' : ' open';
        $id = $editing === null ? 0 : (int) $editing['id'];
        $date = $editing === null ? '' : (string) $editing['lesson_date'];
        $context = $editing === null ? 'Select a lesson' : (string) $editing['snapshot_class_code'] . ' · ' . (string) $editing['snapshot_room_code'] . ' | ' . $date . ' · ' . (string) ($editing['slot_label'] ?? 'Teaching');
        return '<dialog id="lesson-editor"' . $open . '><form method="post"><input type="hidden" name="occurrence_id" value="' . $id . '"><input type="hidden" name="date" value="' . $this->e($date) . '"><button type="button" class="close secondary" data-close>Close</button><p class="eyebrow">Lesson planning</p><h2>Edit lesson planning</h2><p class="lesson-context">' . $this->e($context) . '</p><label class="field-outline">Lesson outline<textarea name="lesson_outline">' . $this->e((string) ($editing['planning_notes'] ?? '')) . '</textarea></label><label class="field-requisitions">Requisitions<textarea name="requisitions">' . $this->e((string) ($editing['requirements_text'] ?? '')) . '</textarea><span class="check-label"><input type="checkbox" data-nothing-required> Nothing required</span></label><label class="field-risk">Risk assessment<textarea name="risk_assessment">' . $this->e((string) ($editing['risk_assessment_text'] ?? '')) . '</textarea></label><div class="form-actions"><button type="submit">Save planning</button></div></form></dialog>';
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) return $this->today;
        return $date;
    }

    private function weekDayLabel(DateTimeImmutable $date): string { return $date->format('l j F Y'); }
    private function periodLabel(TimetableSlot $slot): string { return $slot->label ?: 'P' . $slot->teachingPeriodNumber; }
    private function separatorLabel(TimetableSlot $slot): string
    {
        $label = trim((string) ($slot->label ?? ''));
        if ($label !== '') return $label;
        return match ($slot->kind) { 'break' => 'Break', 'lunch' => 'Lunch', default => 'Other' };
    }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function error(string $message): string { return PageLayout::render('Teacher week', '<p class="notice error">' . $this->e($message) . '</p>', $this->user); }
    private function classTone(string $class): int { return abs(crc32($class)) % 6; }
    private function script(): string { return "document.querySelectorAll('[data-lesson-id]').forEach(function(link){link.addEventListener('click',function(event){var dialog=document.getElementById('lesson-editor');if(!dialog||!dialog.showModal)return;event.preventDefault();dialog.querySelector('input[name=occurrence_id]').value=link.dataset.lessonId;dialog.querySelector('input[name=date]').value=link.dataset.date;dialog.querySelector('h2').textContent='Edit lesson planning';dialog.querySelector('.lesson-context').textContent=link.dataset.class+' · '+link.dataset.room+' | '+link.dataset.date+' · '+link.dataset.period;dialog.querySelector('[name=lesson_outline]').value=link.dataset.outline;dialog.querySelector('[name=requisitions]').value=link.dataset.requisitions;dialog.querySelector('[name=risk_assessment]').value=link.dataset.risk;dialog.querySelector('[data-nothing-required]').checked=link.dataset.requisitions==='Nothing required';dialog.showModal();});});document.querySelectorAll('[data-close]').forEach(function(button){button.addEventListener('click',function(){button.closest('dialog').close();});});document.querySelectorAll('[data-nothing-required]').forEach(function(box){box.addEventListener('change',function(){var field=box.closest('label').querySelector('[name=requisitions]');if(box.checked){field.value='Nothing required';}else if(field.value==='Nothing required'){field.value='';}});});"; }
}
