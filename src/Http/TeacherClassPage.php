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
        $editing = null;
        $form = null;
        $async = $method === 'POST' && (($input['_async'] ?? '') === '1');
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
                if ($section === 'requisitions') $requisitions = ($input['nothing_required'] ?? '') === 'yes' ? 'Nothing required' : (string) ($input['value'] ?? '');
                if ($section === 'risk') $risk = (string) ($input['value'] ?? '');
                if ($async) {
                    $saved = $this->service->saveSection($this->organisationId, $this->teacherId, (int) ($input['occurrence_id'] ?? 0), $section, (string) ($input['value'] ?? ''), ($input['nothing_required'] ?? '') === 'yes');
                    return $this->json(['ok' => true] + $saved);
                }
                $this->service->save(
                    $this->organisationId, $this->teacherId, (int) ($input['occurrence_id'] ?? 0),
                    $outline, $requisitions, $risk,
                );
                $message = 'Lesson planning saved.';
            } catch (TimetableValidationException $exception) { if ($async) return $this->json(['ok' => false, 'message' => implode(' ', $exception->errors())], 422); $message = implode(' ', $exception->errors()); $editing = (int) ($input['occurrence_id'] ?? 0); $form = $input; }
            catch (\Throwable) { if ($async) return $this->json(['ok' => false, 'message' => 'Lesson planning could not be saved. Please try again.'], 500); $message = 'Lesson planning could not be saved. Please try again.'; $editing = (int) ($input['occurrence_id'] ?? 0); $form = $input; }
        }

        try {
            $classes = $this->service->classes($this->organisationId, $this->teacherId);
            if ($classId < 1 && $classes !== []) $classId = (int) $classes[0]['id'];
            if ($classes === []) return $this->render([], null, [], [], $message);
            $data = $this->service->loadClass($this->organisationId, $this->teacherId, $classId, $this->today);
            return $this->render($data['classes'], $data['selected'], $data['previous'], $data['upcoming'], $message, $editing, $form);
        } catch (TimetableValidationException $exception) {
            return $this->render($classes, null, [], [], implode(' ', $exception->errors()), $editing, $form);
        }
    }

    /** @param list<array{id:int,code:string}> $classes @param array{id:int,code:string}|null $selected @param list<array<string,mixed>> $previous @param list<array<string,mixed>> $upcoming */
    private function render(array $classes, ?array $selected, array $previous, array $upcoming, ?string $message, ?int $editing = null, ?array $form = null): string
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
        $body .= '<div class="teacher-day-table-wrap"><table class="teacher-day-table class-view-table"><caption class="visually-hidden">Lessons for class ' . $this->e($selected['code']) . '</caption><thead><tr><th>Day / period / date</th><th>Room</th><th>Requisitions</th><th>Lesson outline</th><th>Risk assessment</th></tr></thead><tbody>';
        foreach ($lessons as $lesson) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $lesson['lesson_date']) ?: $this->today;
            $period = 'P' . (int) ($lesson['teaching_period_number'] ?? 0);
            $body .= '<tr><th scope="row"><span class="lesson-date-box"><span>' . $this->e($date->format('D') . ' ' . $period) . '</span><span>' . $date->format('d/m') . '</span></span></th><td class="lesson-context-cell">' . $this->e((string) $lesson['snapshot_room_code']) . '</td><td>' . $this->editor($lesson, 'requisitions', 'Requisitions', $this->requirements($lesson), $selected['id'], $date, ($lesson['state'] ?? '') === 'nothing_required', $editing, $form) . '</td><td>' . $this->editor($lesson, 'outline', 'Lesson outline', (string) ($lesson['planning_notes'] ?? ''), $selected['id'], $date, false, $editing, $form) . '</td><td>' . $this->editor($lesson, 'risk', 'Risk assessment', (string) ($lesson['risk_assessment_text'] ?? ''), $selected['id'], $date, false, $editing, $form) . '</td></tr>';
        }
        return PageLayout::render('Teacher class', $body . '</tbody></table></div>' . $this->inlineEditingScript(), $this->user);
    }

    private function editor(array $lesson, string $section, string $label, string $value, int $classId, DateTimeImmutable $date, bool $nothingRequired = false, ?int $editing = null, ?array $form = null): string
    {
        $empty = match ($section) { 'outline' => 'No outline entered', 'requisitions' => 'No requisitions entered', default => 'No risk assessment entered' };
        $checkbox = $section === 'requisitions' ? '<label class="check-label"><input type="checkbox" name="nothing_required" value="yes"' . ($nothingRequired ? ' checked' : '') . '> Nothing required</label>' : '';
        $open = $editing === (int) $lesson['id'] && (($form['section'] ?? '') === $section);
        $displayValue = $open ? (string) ($form['value'] ?? '') : $value;
        $displayNothing = $open ? (($form['nothing_required'] ?? '') === 'yes') : $nothingRequired;
        $checkbox = $section === 'requisitions' ? '<label class="check-label"><input type="checkbox" name="nothing_required" value="yes"' . ($displayNothing ? ' checked' : '') . '> Nothing required</label>' : '';
        $formHtml = '<form method="post" class="inline-lesson-form"><input type="hidden" name="csrf_token" value="' . $this->e(CsrfToken::value()) . '"><input type="hidden" name="class_id" value="' . $classId . '"><input type="hidden" name="occurrence_id" value="' . (int) $lesson['id'] . '"><input type="hidden" name="date" value="' . $date->format('Y-m-d') . '"><input type="hidden" name="section" value="' . $this->e($section) . '"><label><span class="visually-hidden">' . $this->e($label) . '</span><textarea name="value"' . ($section === 'requisitions' ? ' class="requisition-editor"' : '') . '>' . $this->e($displayValue) . '</textarea></label>' . $checkbox . '<div class="form-actions"><button type="submit">Save</button><a class="button secondary" href="/teacher/class?class_id=' . $classId . '">Cancel</a></div></form>';
        if ($open) return '<div class="day-lesson-section is-editing" data-inline-planning-cell><div class="day-lesson-heading"><span>' . $this->e($label) . '</span></div>' . $formHtml . '</div>';
        return '<div class="day-lesson-section" data-inline-planning-cell data-section="' . $this->e($section) . '" data-label="' . $this->e($label) . '" data-occurrence-id="' . (int) $lesson['id'] . '" data-class-id="' . $classId . '" data-date="' . $date->format('Y-m-d') . '" data-csrf="' . $this->e(CsrfToken::value()) . '" data-saved-value="' . $this->e($value) . '" data-nothing-required="' . ($nothingRequired ? 'true' : 'false') . '" tabindex="0" role="button" aria-label="Edit ' . $this->e($label) . '"><div class="day-lesson-heading"><span>' . $this->e($label) . '</span></div><div class="day-lesson-value">' . $this->textCell($value, $empty) . '</div></div>';
    }

    private function inlineEditingScript(): string
    {
        return '<script src="/assets/teacher-inline-planning.js" defer></script>';
        return '<script>(function(){var active=null;function resize(a){a.style.height="auto";a.style.height=Math.max(a.scrollHeight,80)+"px";}function text(v,empty){return v?v.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/\\n/g,"<br>"):"<span class=\"muted\">"+empty+"</span>";}function close(cell){if(!cell)return;cell.innerHTML="<div class=\"day-lesson-heading\"><span>"+cell.dataset.label+"</span></div><div class=\"day-lesson-value\">"+text(cell.dataset.savedValue||"",cell.dataset.section==="requisitions"?"No requisitions entered":cell.dataset.section==="outline"?"No outline entered":"No risk assessment entered")+"</div>";cell.classList.remove("is-editing");}function open(cell){if(active&&active!==cell){var old=active.querySelector("textarea");if(old&&old.value!==active.dataset.savedValue&&!window.confirm("Discard unsaved changes?"))return;close(active);}active=cell;cell.classList.add("is-editing");var s=cell.dataset.section,l=cell.dataset.label,v=cell.dataset.savedValue||"",r=s==="requisitions",checked=cell.dataset.nothingRequired==="true";cell.innerHTML="<div class=\"day-lesson-heading\"><span>"+l+"</span></div><form method=\"post\" class=\"inline-lesson-form\"><input type=\"hidden\" name=\"csrf_token\" value=\""+cell.dataset.csrf+"\"><input type=\"hidden\" name=\"date\" value=\""+cell.dataset.date+"\"><input type=\"hidden\" name=\"class_id\" value=\""+cell.dataset.classId+"\"><input type=\"hidden\" name=\"occurrence_id\" value=\""+cell.dataset.occurrenceId+"\"><input type=\"hidden\" name=\"section\" value=\""+s+"\"><label><span class=\"visually-hidden\">"+l+"</span><textarea name=\"value\""+(r?" class=\"requisition-editor\"":"")+"></textarea></label>"+(r?"<label class=\"check-label\"><input type=\"checkbox\" name=\"nothing_required\" value=\"yes\""+(checked?" checked":"")+"> Nothing required</label>":"")+"<div class=\"form-actions\"><button type=\"submit\">Save</button><button type=\"button\" class=\"secondary\" data-inline-cancel>Cancel</button></div></form>";var form=cell.querySelector("form"),area=cell.querySelector("textarea"),box=cell.querySelector("[name=nothing_required]");area.value=v;if(box&&box.checked){area.value="Nothing required";area.disabled=true;}if(box)box.addEventListener("change",function(){if(box.checked){area.dataset.previousValue=area.value==="Nothing required"?"":area.value;area.value="Nothing required";area.disabled=true;}else{area.disabled=false;area.value=area.dataset.previousValue||"";}resize(area);});area.addEventListener("input",function(){resize(area);});form.addEventListener("submit",function(){if(box&&!box.checked)area.disabled=false;});cell.querySelector("[data-inline-cancel]").addEventListener("click",function(){close(cell);active=null;});resize(area);area.focus();area.setSelectionRange(area.value.length,area.value.length);}document.querySelectorAll("[data-inline-planning-cell][data-section]").forEach(function(cell){cell.addEventListener("click",function(e){if(e.target.closest("form,button,a,textarea,input"))return;open(cell);});cell.addEventListener("keydown",function(e){if((e.key==="Enter"||e.key===" ")&&!cell.classList.contains("is-editing")){e.preventDefault();open(cell);}});});window.addEventListener("beforeunload",function(e){if(!active)return;var a=active.querySelector("textarea");if(a&&a.value!==active.dataset.savedValue){e.preventDefault();e.returnValue="";}});})();</script>';
    }

    private function json(array $payload, int $status = 200): string
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        return (string) json_encode($payload, JSON_THROW_ON_ERROR);
    }

    private function requirements(array $lesson): string
    {
        $value = (string) ($lesson['requirements_text'] ?? '');
        return $value === '' && ($lesson['state'] ?? '') === 'nothing_required' ? 'Nothing required' : $value;
    }

    private function textCell(string $value, string $empty): string { return trim($value) === '' ? '<span class="muted">' . $this->e($empty) . '</span>' : nl2br($this->e($value)); }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
