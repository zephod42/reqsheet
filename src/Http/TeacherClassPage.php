<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use DateTimeImmutable;
use Reqsheet\Teacher\TeacherPlanningService;
use Reqsheet\Timetable\TimetableValidationException;
use Reqsheet\Date\DateDisplay;

final class TeacherClassPage
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

    /** @param array<string,mixed> $query @param array<string,mixed> $input */
    public function handle(string $method, array $query = [], array $input = []): string
    {
        $classId = (int) ($query['class_id'] ?? $input['class_id'] ?? 0);
        $message = null;
        $classes = [];
        if ($method === 'POST') {
            try {
                if (!CsrfToken::valid($input['csrf_token'] ?? null)) throw new TimetableValidationException(['Your request expired. Please try again.']);
                $section = (string) ($input['section'] ?? '');
                if (!in_array($section, ['outline', 'requisitions', 'risk'], true)) throw new TimetableValidationException(['Choose a valid lesson section.']);
                $current = $this->service->occurrenceForEdit($this->organisationId, $this->teacherId, (int) ($input['occurrence_id'] ?? 0));
                if ((int) ($current['class_id'] ?? 0) !== (int) ($input['class_id'] ?? 0)) throw new TimetableValidationException(['That lesson is not part of the selected class.']);
                $outline = (string) ($current['planning_notes'] ?? '');
                $requisitions = (string) ($current['requirements_text'] ?? '');
                if (($current['state'] ?? '') === 'nothing_required' && $requisitions === '') $requisitions = 'Nothing required';
                $risk = (string) ($current['risk_assessment_text'] ?? '');
                if ($section === 'outline') $outline = (string) ($input['value'] ?? '');
                if ($section === 'requisitions') $requisitions = (string) ($input['value'] ?? '');
                if ($section === 'risk') $risk = (string) ($input['value'] ?? '');
                $this->service->save(
                    $this->organisationId, $this->teacherId, (int) ($input['occurrence_id'] ?? 0),
                    $outline, $requisitions, $risk,
                );
                $message = 'Lesson planning saved.';
            } catch (TimetableValidationException $exception) { $message = implode(' ', $exception->errors()); }
        }

        try {
            $classes = $this->service->classes($this->organisationId, $this->teacherId);
            if ($classId < 1 && $classes !== []) $classId = (int) $classes[0]['id'];
            if ($classes === []) return $this->render([], null, [], [], $message);
            $data = $this->service->loadClass($this->organisationId, $this->teacherId, $classId, $this->today);
            return $this->render($data['classes'], $data['selected'], $data['previous'], $data['upcoming'], $message);
        } catch (TimetableValidationException $exception) {
            return $this->render($classes, null, [], [], implode(' ', $exception->errors()));
        }
    }

    /** @param list<array{id:int,code:string}> $classes @param array{id:int,code:string}|null $selected @param list<array<string,mixed>> $previous @param list<array<string,mixed>> $upcoming */
    private function render(array $classes, ?array $selected, array $previous, array $upcoming, ?string $message): string
    {
        $body = '<header class="page-header"><div><p class="eyebrow">Teacher</p><h1>Class View</h1></div><a class="button secondary" href="/teacher">Week View</a></header>';
        if ($message !== null) $body .= '<p class="' . (str_contains($message, 'saved') ? 'message' : 'notice error') . '">' . $this->e($message) . '</p>';
        $body .= '<form class="day-picker class-picker" method="get"><label for="teacher-class-id">Class</label><select id="teacher-class-id" name="class_id" onchange="this.form.submit()">';
        if ($classes === []) $body .= '<option value="0">No classes assigned</option>';
        foreach ($classes as $class) $body .= '<option value="' . (int) $class['id'] . '"' . ($selected !== null && $selected['id'] === $class['id'] ? ' selected' : '') . '>' . $this->e($class['code']) . '</option>';
        $body .= '</select><noscript><button>View class</button></noscript></form>';
        if ($classes === []) return PageLayout::render('Teacher class', $body . '<p class="notice">No classes are currently assigned to you.</p>', $this->user);
        if ($selected === null) return PageLayout::render('Teacher class', $body . '<p class="notice error">Class is not available for this teacher.</p>', $this->user);

        $body .= '<p class="context">Showing the three most recent previous lessons, today and the nearest upcoming lessons.</p>';
        $lessons = array_merge($previous, $upcoming);
        if ($lessons === []) return PageLayout::render('Teacher class', $body . '<p class="notice">No lessons are scheduled for this class.</p>', $this->user);
        $body .= '<div class="teacher-day-table-wrap"><table class="teacher-day-table class-view-table"><caption class="visually-hidden">Lessons for class ' . $this->e($selected['code']) . '</caption><thead><tr><th>Date</th><th>Day / period</th><th>Room</th><th>Lesson outline</th><th>Requisitions</th><th>Risk assessment</th></tr></thead><tbody>';
        foreach ($lessons as $lesson) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $lesson['lesson_date']) ?: $this->today;
            $period = (string) ($lesson['slot_label'] ?? ('P' . (int) ($lesson['teaching_period_number'] ?? 0)));
            $body .= '<tr><th scope="row">' . $this->e($this->dateDisplay->format($date)) . '</th><td>' . $this->e($date->format('l') . ' / ' . $period) . '</td><td>' . $this->e((string) $lesson['snapshot_room_code']) . '</td><td>' . $this->editor($lesson, 'outline', 'Lesson outline', (string) ($lesson['planning_notes'] ?? ''), $selected['id'], $date) . '</td><td>' . $this->editor($lesson, 'requisitions', 'Requisitions', $this->requirements($lesson), $selected['id'], $date) . '</td><td>' . $this->editor($lesson, 'risk', 'Risk assessment', (string) ($lesson['risk_assessment_text'] ?? ''), $selected['id'], $date) . '</td></tr>';
        }
        return PageLayout::render('Teacher class', $body . '</tbody></table></div>', $this->user);
    }

    private function editor(array $lesson, string $section, string $label, string $value, int $classId, DateTimeImmutable $date): string
    {
        return '<details class="day-lesson-section"><summary><span>' . $this->e($label) . '</span><span class="section-edit-hint">Edit</span></summary><div class="day-lesson-value">' . $this->textCell($value, $section === 'requisitions' ? 'No requisitions entered' : 'Not entered') . '</div><form method="post" class="inline-lesson-form"><input type="hidden" name="csrf_token" value="' . $this->e(CsrfToken::value()) . '"><input type="hidden" name="class_id" value="' . $classId . '"><input type="hidden" name="occurrence_id" value="' . (int) $lesson['id'] . '"><input type="hidden" name="date" value="' . $date->format('Y-m-d') . '"><input type="hidden" name="section" value="' . $this->e($section) . '"><label><span class="visually-hidden">' . $this->e($label) . '</span><textarea name="value">' . $this->e($value) . '</textarea></label><div class="form-actions"><button>Save</button><a class="button secondary" href="/teacher/class?class_id=' . $classId . '">Cancel</a></div></form></details>';
    }

    private function requirements(array $lesson): string
    {
        $value = (string) ($lesson['requirements_text'] ?? '');
        return $value === '' && ($lesson['state'] ?? '') === 'nothing_required' ? 'Nothing required' : $value;
    }

    private function textCell(string $value, string $empty): string { return trim($value) === '' ? '<span class="muted">' . $this->e($empty) . '</span>' : nl2br($this->e($value)); }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
