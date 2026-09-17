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
        $columns = [];
        foreach ($week->days as $day) foreach ($day['slots'] as $slot) $columns[$slot->sequenceNumber] = $slot;
        ksort($columns);
        $body = '<header class="page-header"><a class="week-arrow" href="?date=' . $week->start->sub(new DateInterval('P7D'))->format('Y-m-d') . '" aria-label="Previous week">‹</a><div><h1>Week beginning ' . $this->e($this->weekDayLabel($week->start)) . '</h1><a class="this-week" href="?date=' . $this->today->format('Y-m-d') . '">This week</a></div><a class="week-arrow" href="?date=' . $week->start->add(new DateInterval('P7D'))->format('Y-m-d') . '" aria-label="Next week">›</a></header>';
        if ($message !== null) $body .= '<p class="message">' . $this->e($message) . '</p>';
        $body .= '<p class="temporary">Temporary teacher review identity</p>';
        $body .= '<div class="timetable-scroll"><table class="week-grid"><thead><tr><th>Day</th>';
        foreach ($columns as $slot) $body .= '<th class="' . ($slot->isTeaching() ? 'teaching-column' : 'separator-column') . '">' . $this->e($slot->isTeaching() ? $this->periodLabel($slot) : ($slot->label ?: ucfirst($slot->kind))) . '</th>';
        $body .= '</tr></thead><tbody>';
        foreach ($week->days as $day) {
            $date = $day['date'];
            $body .= '<tr class="' . ($date->format('Y-m-d') === $this->today->format('Y-m-d') ? 'today-row' : '') . '"><th class="day-label">' . $this->e($date->format('D')) . '<br><small>' . $date->format('j M') . '</small></th>';
            $bySequence = [];
            foreach ($day['slots'] as $slot) $bySequence[$slot->sequenceNumber] = $slot;
            $byStart = [];
            foreach ($day['occurrences'] as $occurrence) $byStart[(int) $occurrence['snapshot_start_slot_id']] = $occurrence;
            $sequences = array_keys($columns);
            for ($index = 0; $index < count($sequences); $index++) {
                $slot = $bySequence[$sequences[$index]] ?? null;
                if ($slot === null) { $body .= '<td></td>'; continue; }
                if (!$slot->isTeaching()) { $body .= '<td class="separator-cell" aria-label="' . $this->e($slot->label ?: $slot->kind) . '"></td>'; continue; }
                $occurrence = $byStart[$slot->id] ?? null;
                if ($occurrence === null) { $body .= '<td class="empty-cell"></td>'; continue; }
                $duration = max(1, (int) $occurrence['snapshot_duration_periods']);
                $body .= '<td colspan="' . $duration . '">' . $this->lessonBlock($occurrence, $date, $slot) . '</td>';
                $index += $duration - 1;
            }
            $body .= '</tr>';
        }
        $body .= '</tbody></table></div>';
        $body .= $this->modal($editing);
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Teacher week · Reqsheet</title><style>' . $this->css() . '</style></head><body><main>' . $body . '</main><script>' . $this->script() . '</script></body></html>';
    }

    /** @param array<string, mixed> $occurrence */
    private function lessonBlock(array $occurrence, DateTimeImmutable $date, TimetableSlot $slot): string
    {
        $requirements = (string) ($occurrence['requirements_text'] ?? '');
        $outline = (string) ($occurrence['planning_notes'] ?? '');
        $risk = (string) ($occurrence['risk_assessment_text'] ?? '');
        $period = $this->periodLabel($slot) . ($occurrence['snapshot_duration_periods'] > 1 ? '–' . $occurrence['snapshot_duration_periods'] : '');
        return '<a class="lesson-block" href="?date=' . $date->format('Y-m-d') . '&edit=' . (int) $occurrence['id'] . '" data-lesson-id="' . (int) $occurrence['id'] . '" data-class="' . $this->e((string) $occurrence['snapshot_class_code']) . '" data-room="' . $this->e((string) $occurrence['snapshot_room_code']) . '" data-date="' . $date->format('Y-m-d') . '" data-period="' . $this->e($period) . '" data-outline="' . $this->e($outline) . '" data-requisitions="' . $this->e($requirements) . '" data-risk="' . $this->e($risk) . '"><span class="lesson-header"><strong>' . $this->e((string) $occurrence['snapshot_class_code']) . '</strong><span>' . $this->e((string) $occurrence['snapshot_room_code']) . '</span></span><span class="lesson-body">' . ($requirements === '' ? '<span class="muted">No requisitions entered</span>' : $this->e($requirements)) . '</span></a>';
    }

    /** @param array<string, mixed>|null $editing */
    private function modal(?array $editing): string
    {
        $open = $editing === null ? '' : ' open';
        $id = $editing === null ? 0 : (int) $editing['id'];
        $date = $editing === null ? '' : (string) $editing['lesson_date'];
        $context = $editing === null ? 'Select a lesson' : (string) $editing['snapshot_class_code'] . ' · ' . (string) $editing['snapshot_room_code'] . ' | ' . $date . ' · ' . (string) ($editing['slot_label'] ?? 'Teaching');
        return '<dialog id="lesson-editor"' . $open . '><form method="post"><input type="hidden" name="occurrence_id" value="' . $id . '"><input type="hidden" name="date" value="' . $this->e($date) . '"><button type="button" class="close" data-close>Close</button><h2>Edit lesson planning</h2><p class="lesson-context">' . $this->e($context) . '</p><label>Lesson outline<textarea name="lesson_outline">' . $this->e((string) ($editing['planning_notes'] ?? '')) . '</textarea></label><label>Requisitions<textarea name="requisitions">' . $this->e((string) ($editing['requirements_text'] ?? '')) . '</textarea></label><label>Risk assessment<textarea name="risk_assessment">' . $this->e((string) ($editing['risk_assessment_text'] ?? '')) . '</textarea></label><button type="submit">Save</button></form></dialog>';
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) return $this->today;
        return $date;
    }

    private function weekDayLabel(DateTimeImmutable $date): string { return $date->format('l j F Y'); }
    private function periodLabel(TimetableSlot $slot): string { return $slot->label ?: 'P' . $slot->teachingPeriodNumber; }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function error(string $message): string { return '<!doctype html><meta charset="utf-8"><title>Teacher week · Reqsheet</title><p>' . $this->e($message) . '</p>'; }
    private function css(): string { return 'body{margin:0;background:#f5f7fa;color:#222;font:16px system-ui,sans-serif}main{max-width:1200px;margin:0 auto;padding:1rem}.page-header{display:flex;align-items:center;justify-content:space-between;gap:1rem}.page-header h1{font-size:1.45rem;text-align:center;margin:.2rem 0}.week-arrow{font-size:2.4rem;text-decoration:none;padding:.3rem .8rem}.this-week{display:block;text-align:center;font-size:.9rem}.temporary{color:#666;font-size:.8rem}.timetable-scroll{overflow-x:auto;max-width:100%;box-shadow:0 1px 3px #ccd}.week-grid{border-collapse:collapse;table-layout:fixed;min-width:760px;width:100%;background:#fff}.week-grid th,.week-grid td{border:1px solid #d0d6dd}.week-grid thead th{height:2.5rem;padding:.35rem}.week-grid thead th:first-child{width:6rem}.teaching-column{min-width:9rem}.separator-column{width:2rem;min-width:2rem;background:#ddd;color:#666;writing-mode:vertical-rl;font-size:.75rem}.day-label{padding:.6rem;text-align:left;white-space:nowrap;background:#fafafa}.today-row .day-label{background:#eef5ff}.week-grid td{height:7rem;padding:.25rem;vertical-align:top}.separator-cell{background:#ddd}.empty-cell{background:#fff}.lesson-block{display:flex;flex-direction:column;height:6.5rem;overflow:hidden;text-decoration:none;color:inherit;border:1px solid #b9c9da;border-radius:3px;background:#fff}.lesson-header{display:flex;justify-content:space-between;gap:.4rem;background:#e8f1fa;padding:.35rem .45rem;font-size:.9rem}.lesson-header span{font-weight:normal}.lesson-body{padding:.45rem;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical}.muted{color:#777;font-style:italic}.message{background:#fff3cd;padding:.7rem}.close{float:right}dialog{border:1px solid #8899aa;border-radius:4px;padding:1.2rem;max-width:34rem;width:calc(100% - 3rem)}dialog::backdrop{background:#0008}dialog label{display:block;margin:.8rem 0}textarea{display:block;width:100%;min-height:5rem;box-sizing:border-box;font:inherit;padding:.4rem}button{font:inherit;padding:.5rem .8rem;touch-action:manipulation}'; }
    private function script(): string { return "document.querySelectorAll('[data-lesson-id]').forEach(function(link){link.addEventListener('click',function(event){var dialog=document.getElementById('lesson-editor');if(!dialog||!dialog.showModal)return;event.preventDefault();dialog.querySelector('input[name=occurrence_id]').value=link.dataset.lessonId;dialog.querySelector('input[name=date]').value=link.dataset.date;dialog.querySelector('h2').textContent='Edit lesson planning';dialog.querySelector('.lesson-context').textContent=link.dataset.class+' · '+link.dataset.room+' | '+link.dataset.date+' · '+link.dataset.period;dialog.querySelector('[name=lesson_outline]').value=link.dataset.outline;dialog.querySelector('[name=requisitions]').value=link.dataset.requisitions;dialog.querySelector('[name=risk_assessment]').value=link.dataset.risk;dialog.showModal();});});document.querySelectorAll('[data-close]').forEach(function(button){button.addEventListener('click',function(){button.closest('dialog').close();});});"; }
}
