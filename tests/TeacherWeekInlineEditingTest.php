<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use DateTimeImmutable;
use Reqsheet\Http\CsrfToken;
use Reqsheet\Http\TeacherWeekPage;
use Reqsheet\Teacher\TeacherPlanningService;

final class TeacherWeekInlineEditingTest
{
    public static function run(): void
    {
        $store = new TeacherStore();
        $page = new TeacherWeekPage(new TeacherPlanningService($store), 1, 10, new DateTimeImmutable('2026-09-09'));
        $view = $page->handle('GET', ['date' => '2026-09-09'], []);
        assertContainsValue('class="week-grid"', $view, 'Week View no longer uses the timetable grid.');
        assertContainsValue('class="lesson-block class-tone-', $view, 'Week View lesson card styling was removed.');
        assertContainsValue('class="lesson-header"', $view, 'Week View lesson header structure changed.');
        assertContainsValue('data-week-requisition', $view, 'Week View requisitions are not inline-editable.');
        assertNotContainsValue('data-week-nr', $view, 'Week View still exposes the removed NR control.');
        assertNotContainsValue('name="lesson_outline"', $view, 'Week View still emits lesson outline editing controls.');
        assertNotContainsValue('name="risk_assessment"', $view, 'Week View still emits risk assessment editing controls.');
        assertContainsValue('data-week-csrf', $view, 'Week View did not expose a CSRF token for inline saves.');

        $saved = json_decode($page->handle('POST', [], [
            '_async' => '1', 'csrf_token' => CsrfToken::value(), 'occurrence_id' => 500,
            'section' => 'requisitions', 'value' => "Bring goggles\nWear gloves",
        ]), true);
        assertSameValue(true, $saved['ok'] ?? false, 'Week inline save failed.');
        assertSameValue("Bring goggles\nWear gloves", $store->occurrences[500]['requirements_text'], 'Week inline save did not preserve multiline text.');
        assertSameValue('requirements_entered', $saved['state'] ?? null, 'Week inline save did not return the authoritative planning state.');
        assertSameValue(true, $saved['populated'] ?? false, 'Week inline save did not return populated planning state.');
        assertSameValue("Bring goggles\nWear gloves", $saved['requisitions'] ?? null, 'Week inline save did not return the saved requisitions.');
        assertSameValue(TeacherPlanningService::planningRevision($store->occurrences[500]), $saved['planning_revision'] ?? null, 'Week inline save did not return the authoritative planning revision.');

        $nr = json_decode($page->handle('POST', [], [
            '_async' => '1', 'csrf_token' => CsrfToken::value(), 'occurrence_id' => 500,
            'section' => 'requisitions', 'value' => "Bring goggles\nWear gloves", 'nothing_required' => 'yes',
        ]), true);
        assertSameValue(true, $nr['nothing_required'] ?? false, 'NR did not set Nothing Required state.');
        assertSameValue("Bring goggles\nWear gloves", $store->occurrences[500]['requirements_text'], 'NR erased the previous text.');

        $script = (string) file_get_contents(__DIR__ . '/../public/assets/teacher-week.js');
        assertContainsValue('openRequisition', $script, 'Week View did not install an inline requisition editor.');
        assertContainsValue("area.addEventListener('blur'", $script, 'Week editor did not save on blur.');
        assertContainsValue("event.key === 'Tab'", $script, 'Week editor did not save on Tab.');
        assertNotContainsValue('data.set(\'nothing_required\'', $script, 'Week editor still submits the removed NR control.');
        assertContainsValue('applyPlanningState', $script, 'Week inline save did not synchronize lesson planning attributes.');
        assertContainsValue('planningCopyAllowed', $script, 'Week drag-and-drop did not guard pending planning saves.');

        $css = (string) file_get_contents(__DIR__ . '/../public/assets/app.css');
        assertContainsValue('.week-requisition-editor', $css, 'Week editor styling was not added.');
        assertContainsValue('.inline-lesson-form .requisition-editor { min-height: 5rem; }', $css, 'Day/Class requisition editor height was not matched.');
    }
}
