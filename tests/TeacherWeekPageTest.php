<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use DateTimeImmutable;
use Reqsheet\Http\TeacherAccess;
use Reqsheet\Http\TeacherWeekPage;
use Reqsheet\Http\TeacherDayPage;
use Reqsheet\Http\CsrfToken;
use Reqsheet\Teacher\TeacherPlanningService;
use Reqsheet\Teacher\TeacherPlanningStore;
use Reqsheet\Timetable\TimetableSlot;
use Reqsheet\Timetable\TimetableVersion;

final class TeacherWeekPageTest
{
    public static function run(): void
    {
        assertSameValue(1, TeacherAccess::teacherId(['REQSHEET_TEACHER_ID' => '1']), 'Teacher identity was not parsed.');
        assertSameValue(2, TeacherAccess::organisationId(['REQSHEET_TEACHER_ORGANISATION_ID' => '2']), 'Teacher organisation was not parsed.');
        assertSameValue(true, TeacherAccess::configured(['REQSHEET_TEACHER_ID' => '1', 'REQSHEET_TEACHER_ORGANISATION_ID' => '2', 'REQSHEET_TEACHER_KEY' => 'key']), 'Teacher access was not recognised as configured.');
        assertSameValue(true, TeacherAccess::allowed(['REQSHEET_TEACHER_KEY' => 'key'], ['PHP_AUTH_USER' => 'teacher', 'PHP_AUTH_PW' => 'key']), 'Valid teacher credentials were rejected.');
        assertSameValue(7, (new TeacherPlanningService(new TeacherStore(), 1))->weekStart(new DateTimeImmutable('2026-09-13'))->format('N'), 'Week calculation was incorrect.');

        $store = new TeacherStore();
        $page = new TeacherWeekPage(new TeacherPlanningService($store), 1, 10, new DateTimeImmutable('2026-09-09'), ['staff_identifier' => 'NEV']);
        $view = $page->handle('GET', ['date' => '2026-09-09'], []);
        assertContainsValue('Week beginning Monday 7 September 2026', $view, 'Week heading was not rendered.');
        assertNotContainsValue('This Week', $view, 'Week heading used the navigation label instead of the selected week.');
        assertContainsValue('Period One', $view, 'Configured teaching-period label was not rendered.');
        assertContainsValue('Break', $view, 'Configured separator was not rendered.');
        assertContainsValue('Lunch', $view, 'Lunch separator was not rendered in the teacher view.');
        assertSameValue(1, substr_count($view, '>Break<'), 'Break was repeated outside the period axis.');
        assertSameValue(1, substr_count($view, '>Lunch<'), 'Lunch was repeated outside the period axis.');
        assertContainsValue('class="separator-cell"', $view, 'Separator cells were not visibly marked as neutral cells.');
        assertContainsValue('>NE</p>', $view, 'Authenticated teacher initials were not rendered.');
        assertContainsValue('13PHY', $view, 'Lesson class was not rendered.');
        assertContainsValue('class-tone-', $view, 'Teacher lesson did not receive a deterministic class colour.');
        assertContainsValue('LAB-A', $view, 'Lesson room was not rendered.');
        assertContainsValue('Bring goggles', $view, 'Requisitions were not rendered.');
        assertNotContainsValue('Plan the experiment', $view, 'Lesson outline leaked into the normal lesson block.');
        assertNotContainsValue('Wear eye protection', $view, 'Risk assessment leaked into the normal lesson block.');
        assertContainsValue('Previous week', $view, 'Previous-week navigation was not rendered.');
        assertContainsValue('Next week', $view, 'Next-week navigation was not rendered.');
        assertContainsValue('This week', $view, 'This-week control was not rendered.');
        assertContainsValue('week-context-current', $view, 'Current calendar week did not receive its contextual label.');
        assertContainsValue('>GO TO TODAY</a>', $view, 'Return-to-current-week action did not use the requested label.');
        assertContainsValue('class="week-navigation-actions"><a class="go-to-today" href="?date=2026-09-09">GO TO TODAY</a><a class="week-arrow"', $view, 'GO TO TODAY was not positioned with the right-hand week navigation controls.');
        assertNotContainsValue('GO TO TODAY</a></div><a href="/teacher/day', $view, 'GO TO TODAY was incorrectly linked to Teacher Day View.');
        assertContainsValue('/teacher/day?date=2026-09-07', $view, 'Week day headings did not link to the teacher day view.');
        assertContainsValue('/teacher/class', \Reqsheet\Http\PageLayout::render('Teacher', '<p>Teacher</p>', ['id' => 10, 'organisation_id' => 1, 'roles' => ['teacher']]), 'Teacher navigation did not expose Class View.');
        assertContainsValue('today-row', $view, 'Current day was not gently highlighted.');
        assertSameValue(true, $store->ensured, 'Teacher week did not prepare effective recurring assignments for the selected week.');
        $css = (string) file_get_contents(__DIR__ . '/../public/assets/app.css');
        assertContainsValue('.period-label { width: 1%;', $css, 'Teacher period column was not narrowed.');
        assertContainsValue('text-align: center; vertical-align: middle; white-space: nowrap;', $css, 'Teacher period labels were not centred without truncation.');
        assertContainsValue('.day-label { padding: .6rem; text-align: center; vertical-align: middle;', $css, 'Teacher day headings were not centred.');
        assertContainsValue('.teacher-day-table { width: 100%; min-width: 48rem; border-collapse: collapse; table-layout: fixed;', $css, 'Teacher day/class views did not retain a compact bounded table layout.');
        assertContainsValue('.teacher-day-table thead th:nth-child(2) { width: 5.5rem; }', $css, 'Teacher day/class context column was not narrowed.');
        assertContainsValue('overflow-wrap: anywhere; word-break: break-word;', $css, 'Teacher day/class context codes were not configured to wrap.');
        assertContainsValue('.lesson-date-box { display: inline-flex; flex-direction: column;', $css, 'Teacher day/class views did not style the combined date box.');
        assertContainsValue('.day-lesson-value { margin-top: .3rem; overflow-wrap: anywhere; white-space: pre-wrap;', $css, 'Teacher day/class planning text was not configured to wrap in full.');

        $store->firstDay = 3;
        $alternateWeek = new TeacherWeekPage(new TeacherPlanningService($store), 1, 10, new DateTimeImmutable('2026-09-23'), ['staff_identifier' => 'NEV']);
        assertContainsValue('Week beginning Wednesday 23 September 2026', $alternateWeek->handle('GET', ['date' => '2026-09-23'], []), 'Week heading ignored the configured first day.');
        assertContainsValue('Week beginning Wednesday 30 September 2026', $alternateWeek->handle('GET', ['date' => '2026-09-30'], []), 'Future week heading did not follow navigation selection.');
        assertContainsValue('week-context-current', $alternateWeek->handle('GET', ['date' => '2026-09-23'], []), 'Configured first working day was not used for the current-week label.');
        assertContainsValue('week-context-previous', $alternateWeek->handle('GET', ['date' => '2026-09-16'], []), 'Configured first working day was not used for the previous-week label.');
        assertContainsValue('week-context-next', $alternateWeek->handle('GET', ['date' => '2026-09-30'], []), 'Configured first working day was not used for the next-week label.');
        $store->firstDay = 1;
        assertContainsValue('week-context-previous', $page->handle('GET', ['date' => '2026-09-02'], []), 'Immediately preceding week was not labelled Previous week.');
        assertContainsValue('week-context-next', $page->handle('GET', ['date' => '2026-09-16'], []), 'Immediately following week was not labelled Next week.');
        $distant = $page->handle('GET', ['date' => '2026-09-23'], []);
        assertNotContainsValue('week-context-current', $distant, 'A distant week was labelled as current.');
        assertNotContainsValue('week-context-previous', $distant, 'A distant week was labelled as previous.');
        assertNotContainsValue('week-context-next', $distant, 'A distant week was labelled as next.');
        $yearBoundary = new TeacherWeekPage(new TeacherPlanningService($store), 1, 10, new DateTimeImmutable('2027-01-02'), ['staff_identifier' => 'NEV']);
        assertContainsValue('week-context-current', $yearBoundary->handle('GET', ['date' => '2026-12-31'], []), 'Current-week comparison failed across a year boundary.');
        assertContainsValue('week-context-next', $yearBoundary->handle('GET', ['date' => '2027-01-04'], []), 'Next-week comparison failed across a year boundary.');

        $editing = $page->handle('GET', ['date' => '2026-09-09', 'edit' => 500], []);
        assertContainsValue('Lesson outline', $editing, 'Planning editor did not expose lesson outline.');
        assertContainsValue('Risk assessment', $editing, 'Planning editor did not expose risk assessment.');
        assertContainsValue('LAB-A', $editing, 'Planning editor did not identify the room.');
        assertContainsValue('Nothing required', $editing, 'Planning editor did not expose the explicit blank requisition action.');

        $page->handle('POST', [], [
            'csrf_token' => CsrfToken::value(),
            'date' => '2026-09-09', 'occurrence_id' => 500,
            'lesson_outline' => 'Updated outline', 'requisitions' => 'Updated requisitions', 'risk_assessment' => 'Updated risk',
        ]);
        assertSameValue('Updated requisitions', $store->occurrences[500]['requirements_text'], 'Dated requisition was not saved.');
        assertSameValue('13PHY', $store->occurrences[500]['snapshot_class_code'], 'Recurring timetable data was changed while saving planning.');
        $page->handle('POST', [], ['date' => '2026-09-09', 'occurrence_id' => 500, 'requisitions' => 'Nothing required']);
        assertSameValue('nothing_required', $store->occurrences[500]['state'], 'Nothing required did not set the explicit requisition state.');
        $reloaded = $page->handle('GET', ['date' => '2026-09-09', 'edit' => 500], []);
        assertContainsValue('Updated outline', $reloaded, 'Saved outline did not reload.');
        assertContainsValue('Updated requisitions', $reloaded, 'Saved requisitions did not reload.');
        assertContainsValue('Updated risk', $reloaded, 'Saved risk assessment did not reload.');

        $dayPage = new TeacherDayPage(new TeacherPlanningService($store), 1, 10, new DateTimeImmutable('2026-09-07'), ['staff_identifier' => 'NEV', 'roles' => ['teacher']]);
        $day = $dayPage->handle('GET', ['date' => '2026-09-07']);
        assertContainsValue('Day View', $day, 'Teacher day view heading was not rendered.');
        assertContainsValue("Today's lessons", $day, 'Teacher day view did not identify the selected day.');
        assertContainsValue('P1–P2', $day, 'Multi-period lesson did not appear as a compact single period range.');
        assertContainsValue('Mon P1', $day, 'Day View did not combine the abbreviated day and period.');
        assertContainsValue('07/09', $day, 'Day View did not render the compact date without the year.');
        assertContainsValue('Day / period / date', $day, 'Day View did not retain its compact lesson table heading.');
        assertContainsValue('Class / room', $day, 'Day View did not keep class and room in one compact context column.');
        assertColumnHeadings($day, ['Day / period / date', 'Class / room', 'Requisitions', 'Lesson outline', 'Risk assessment'], 'Day View column order changed unexpectedly.');
        assertContainsValue('Plan the experiment', $day, 'Day view did not show the complete lesson outline.');
        assertContainsValue('Bring goggles', $day, 'Day view did not show the complete requisition text.');
        assertContainsValue('Wear eye protection', $day, 'Day view did not show the complete risk assessment.');
        assertContainsValue('name="section" value="outline"', $day, 'Day view did not provide inline outline editing.');
        assertContainsValue('name="section" value="requisitions"', $day, 'Day view did not provide inline requisition editing.');
        assertContainsValue('name="section" value="risk"', $day, 'Day view did not provide inline risk editing.');
        assertContainsValue('/assets/teacher-inline-planning.js', $day, 'Day view did not load the shared asynchronous inline editor.');
        assertContainsValue('data-inline-planning-cell data-section="requisitions"', $day, 'Day view requisitions cell was not directly interactive.');
        assertContainsValue('data-inline-planning-cell data-section="outline"', $day, 'Day view outline cell was not directly interactive.');
        assertContainsValue('data-inline-planning-cell data-section="risk"', $day, 'Day view risk cell was not directly interactive.');
        assertNotContainsValue('>Edit</summary>', $day, 'Day view retained a separate Edit control.');
        assertNotContainsValue('lesson-edit-control', $day, 'Day view retained an anchored editor control.');
        assertContainsValue('No outline entered', $day, 'Day View did not define the outline empty state.');
        assertContainsValue('No risk assessment entered', $day, 'Day View did not define the risk empty state.');
        assertContainsValue('<tr><th scope="row"><span class="lesson-date-box">', $day, 'Day View did not retain one table row per lesson.');
        assertNotContainsValue('href="/teacher?date=2026-09-07&edit=500"', $day, 'Day view still redirected to the week editor.');
        assertContainsValue('Previous day', $day, 'Day view did not render previous-day navigation.');
        assertContainsValue('type="date"', $day, 'Day view did not render a date picker.');
        $savedDayResponse = $dayPage->handle('POST', [], [
            'csrf_token' => CsrfToken::value(), 'date' => '2026-09-07', 'occurrence_id' => 500,
            'section' => 'outline', 'value' => 'Updated directly in day view',
        ]);
        assertContainsValue('Day View', $savedDayResponse, 'Day View save did not remain on the selected page.');
        assertNotContainsValue('href="/teacher?date=', $savedDayResponse, 'Day View save redirected to the week editor.');
        $editedDay = $dayPage->handle('GET', ['date' => '2026-09-07']);
        assertContainsValue('Updated directly in day view', $editedDay, 'Day View did not display the saved inline edit.');
        assertSameValue('13PHY', $store->occurrences[500]['snapshot_class_code'], 'Day View changed recurring lesson data.');
        $asyncDayResponse = $dayPage->handle('POST', ['date' => '2026-09-07'], [
            '_async' => '1', 'csrf_token' => CsrfToken::value(), 'date' => '2026-09-07', 'occurrence_id' => 500,
            'section' => 'risk', 'value' => 'Async risk update',
        ]);
        $asyncDay = json_decode($asyncDayResponse, true);
        assertSameValue(true, $asyncDay['ok'] ?? false, 'Day View asynchronous save was not confirmed.');
        assertSameValue('Async risk update', $asyncDay['value'] ?? null, 'Day View asynchronous save did not return the saved field.');
        assertSameValue('Async risk update', $store->occurrences[500]['risk_assessment_text'], 'Day View asynchronous save changed the wrong field.');
        assertSameValue('Updated requisitions', $store->occurrences[500]['requirements_text'], 'Day View asynchronous save overwrote requisitions.');
        $asyncNothingResponse = $dayPage->handle('POST', ['date' => '2026-09-07'], [
            '_async' => '1', 'csrf_token' => CsrfToken::value(), 'date' => '2026-09-07', 'occurrence_id' => 500,
            'section' => 'requisitions', 'value' => '', 'nothing_required' => 'yes',
        ]);
        $asyncNothing = json_decode($asyncNothingResponse, true);
        assertSameValue(true, $asyncNothing['nothing_required'] ?? false, 'Asynchronous Nothing required save lost its explicit state.');
        assertSameValue('Nothing required', $asyncNothing['value'] ?? null, 'Asynchronous Nothing required save did not return its display value.');
        $failedAsyncResponse = $dayPage->handle('POST', ['date' => '2026-09-07'], [
            '_async' => '1', 'csrf_token' => 'invalid', 'date' => '2026-09-07', 'occurrence_id' => 500,
            'section' => 'risk', 'value' => 'Unpersisted risk',
        ]);
        $failedAsync = json_decode($failedAsyncResponse, true);
        assertSameValue(false, $failedAsync['ok'] ?? true, 'Failed asynchronous save was reported as successful.');
        assertSameValue('Async risk update', $store->occurrences[500]['risk_assessment_text'], 'Failed asynchronous save changed persisted data.');
        $unchanged = $store->occurrences[500]['requirements_text'];
        $dayPage->handle('GET', ['date' => '2026-09-07', 'edit' => 500]);
        assertSameValue($unchanged, $store->occurrences[500]['requirements_text'], 'Opening an inline editor changed requisitions.');
        $rejected = $dayPage->handle('POST', [], [
            'date' => '2026-09-07', 'occurrence_id' => 500, 'section' => 'risk', 'value' => 'Unsubmitted risk',
        ]);
        assertContainsValue('Unsubmitted risk', $rejected, 'Failed CSRF validation did not preserve entered text.');
        $dayPage->handle('POST', [], [
            'csrf_token' => CsrfToken::value(), 'date' => '2026-09-07', 'occurrence_id' => 500,
            'section' => 'requisitions', 'value' => '', 'nothing_required' => 'yes',
        ]);
        assertSameValue('nothing_required', $store->occurrences[500]['state'], 'Inline Nothing required editing changed state semantics.');
        $emptyDay = $dayPage->handle('GET', ['date' => '2026-09-08']);
        assertContainsValue('No lessons are scheduled for this date.', $emptyDay, 'Day view did not render its empty state.');

        $classPage = new \Reqsheet\Http\TeacherClassPage(new TeacherPlanningService($store), 1, 10, new DateTimeImmutable('2026-09-09'), ['staff_identifier' => 'NEV', 'roles' => ['teacher']]);
        $class = $classPage->handle('GET', ['class_id' => 301]);
        assertContainsValue('Class View', $class, 'Class View did not render.');
        assertContainsValue('option value="301" selected', $class, 'Class View did not select the authorised class.');
        assertContainsValue('Plan the experiment', $class, 'Class View did not show the dated lesson planning data.');
        assertContainsValue('Mon P1', $class, 'Class View did not combine the abbreviated day and period.');
        assertContainsValue('07/09', $class, 'Class View did not render the compact date without the year.');
        assertContainsValue('Day / period / date', $class, 'Class View did not retain its compact lesson table heading.');
        assertContainsValue('<tr><th scope="row"><span class="lesson-date-box">', $class, 'Class View did not retain one table row per lesson.');
        assertContainsValue('name="nothing_required"', $class, 'Class View did not preserve the Nothing required editing control.');
        assertContainsValue('/assets/teacher-inline-planning.js', $class, 'Class View did not load the shared asynchronous inline editor.');
        assertContainsValue('data-inline-planning-cell data-section="requisitions"', $class, 'Class View requisitions cell was not directly interactive.');
        assertContainsValue('data-inline-planning-cell data-section="outline"', $class, 'Class View outline cell was not directly interactive.');
        assertContainsValue('data-inline-planning-cell data-section="risk"', $class, 'Class View risk cell was not directly interactive.');
        assertNotContainsValue('>Edit</summary>', $class, 'Class View retained a separate Edit control.');
        assertNotContainsValue('lesson-edit-control', $class, 'Class View retained an anchored editor control.');
        assertColumnHeadings($class, ['Day / period / date', 'Room', 'Requisitions', 'Lesson outline', 'Risk assessment'], 'Class View column order changed unexpectedly.');
        $asyncClassResponse = $classPage->handle('POST', ['class_id' => 301], [
            '_async' => '1', 'csrf_token' => CsrfToken::value(), 'class_id' => 301, 'occurrence_id' => 500,
            'section' => 'outline', 'value' => 'Async class outline',
        ]);
        $asyncClass = json_decode($asyncClassResponse, true);
        assertSameValue(true, $asyncClass['ok'] ?? false, 'Class View asynchronous save was not confirmed.');
        assertSameValue('Async class outline', $asyncClass['value'] ?? null, 'Class View asynchronous save did not return the saved field.');
        assertSameValue('Async class outline', $store->occurrences[500]['planning_notes'], 'Class View asynchronous outline was not saved.');
        assertSameValue('Nothing required', $store->occurrences[500]['requirements_text'], 'Class View asynchronous outline overwrote requisitions.');
        $inlineScript = (string) file_get_contents(__DIR__ . '/../public/assets/teacher-inline-planning.js');
        assertContainsValue('event.preventDefault()', $inlineScript, 'Inline Save did not prevent ordinary form navigation.');
        assertContainsValue("window.addEventListener('beforeunload'", $inlineScript, 'Inline editor lost the unsaved-change warning.');
        assertContainsValue("form.dataset.saving === 'true'", $inlineScript, 'Inline Save did not guard against repeated submissions.');
        assertContainsValue('data.set(\'value\', area.value)', $inlineScript, 'Failed asynchronous save would not preserve the entered text for retry.');
        assertContainsValue('button.disabled = true', $inlineScript, 'Inline Save did not disable the submit button while saving.');
        $boundedStore = new TeacherStore();
        for ($offset = 1; $offset <= 4; $offset++) {
            $row = $boundedStore->occurrences[500];
            $row['id'] = 600 + $offset; $row['lesson_date'] = '2026-08-' . str_pad((string) $offset, 2, '0', STR_PAD_LEFT);
            $boundedStore->occurrences[$row['id']] = $row;
        }
        for ($offset = 0; $offset < 22; $offset++) {
            $row = $boundedStore->occurrences[500];
            $row['id'] = 700 + $offset; $row['lesson_date'] = (new DateTimeImmutable('2026-09-09'))->modify('+' . $offset . ' days')->format('Y-m-d');
            $boundedStore->occurrences[$row['id']] = $row;
        }
        $bounded = (new TeacherPlanningService($boundedStore))->loadClass(1, 10, 301, new DateTimeImmutable('2026-09-09'));
        assertSameValue(3, count($bounded['previous']), 'Class View did not bound previous lessons to the three most recent.');
        assertSameValue(21, count($bounded['upcoming']), 'Class View did not bound upcoming lessons to the nearest plus twenty subsequent lessons.');
        assertSameValue([1, 10, 301], $boundedStore->lastRangeScope, 'Class View occurrence generation was not scoped to its organisation, teacher, and class.');
        $boundedStore->classes[] = ['id' => 302, 'code' => '12CHEM'];
        $boundedStore->classes[] = ['id' => 303, 'code' => '11BIO'];
        $severalClassesPage = new \Reqsheet\Http\TeacherClassPage(new TeacherPlanningService($boundedStore), 1, 10, new DateTimeImmutable('2026-09-09'));
        $severalClasses = $severalClassesPage->handle('GET', ['class_id' => 301]);
        assertContainsValue('12CHEM', $severalClasses, 'Class View did not list a teacher\'s second assigned class.');
        assertContainsValue('11BIO', $severalClasses, 'Class View did not list a teacher\'s third assigned class.');
        $invalidClass = $classPage->handle('GET', ['class_id' => 999]);
        assertContainsValue('Class is not available for this teacher.', $invalidClass, 'Unauthorised class identifier was not rejected.');
        assertNotContainsValue('Plan the experiment', $invalidClass, 'Unauthorised class data leaked into Class View.');
        $store->noClasses = true;
        $store->ensured = false;
        assertContainsValue('No classes are currently assigned to you.', $classPage->handle('GET', []), 'Class View did not render its no-class empty state.');
        assertSameValue(false, $store->ensured, 'The no-class empty state unnecessarily generated occurrences.');
        $store->noClasses = false;

        $foreign = new TeacherWeekPage(new TeacherPlanningService($store), 2, 10, new DateTimeImmutable('2026-09-09'));
        assertContainsValue('Teacher does not belong to the requested organisation.', $foreign->handle('GET', [], []), 'Foreign organisation was not rejected.');

        $copyStore = new TeacherStore();
        $copyStore->occurrences[501] = [
            'id' => 501, 'lesson_date' => '2026-09-08', 'timetable_version_id' => 1, 'snapshot_teacher_user_id' => 10,
            'class_id' => 302, 'snapshot_class_code' => '12CHEM', 'snapshot_room_code' => 'LAB-B', 'snapshot_start_slot_id' => 201,
            'snapshot_duration_periods' => 1, 'day_of_week' => 2, 'teaching_period_number' => 1, 'slot_label' => 'Period One',
            'state' => 'not_completed', 'requirements_text' => null, 'planning_notes' => null, 'risk_assessment_text' => null,
            'prepared_at' => '2026-09-08 07:30:00',
        ];
        $copyStore->occurrences[502] = $copyStore->occurrences[501] + [];
        $copyStore->occurrences[502]['id'] = 502;
        $copyStore->occurrences[502]['snapshot_teacher_user_id'] = 99;
        $copyStore->occurrences[502]['snapshot_start_slot_id'] = 104;
        $copyStore->occurrences[502]['lesson_date'] = '2026-09-07';
        $copyStore->occurrences[503] = $copyStore->occurrences[501] + [];
        $copyStore->occurrences[503]['id'] = 503;
        $copyStore->occurrences[503]['lesson_date'] = '2026-09-14';
        $copyStore->occurrences[504] = $copyStore->occurrences[501] + [];
        $copyStore->occurrences[504]['id'] = 504;
        $copyStore->occurrences[504]['snapshot_start_slot_id'] = 104;
        $copyStore->occurrences[504]['lesson_date'] = '2026-09-07';
        $copyPage = new TeacherWeekPage(new TeacherPlanningService($copyStore), 1, 10, new DateTimeImmutable('2026-09-09'), ['staff_identifier' => 'NEV']);
        $copyView = $copyPage->handle('GET', ['date' => '2026-09-09'], []);
        assertContainsValue('/assets/teacher-week.js', $copyView, 'Week View did not load the drag-and-drop controller.');
        assertContainsValue('Duplicate lesson…', $copyView, 'Keyboard-accessible duplication action was not exposed by the lesson editor.');
        assertContainsValue('data-planning-revision=', $copyView, 'Lesson tiles did not expose a planning concurrency token.');
        $copyResponse = json_decode($copyPage->handle('POST', [], [
            'action' => 'duplicate', '_async' => '1', 'csrf_token' => CsrfToken::value(), 'date' => '2026-09-09',
            'source_occurrence_id' => 500, 'target_occurrence_id' => 501,
        ]), true);
        assertSameValue(true, $copyResponse['ok'] ?? false, 'Empty-target duplication did not succeed.');
        assertSameValue('Bring goggles', $copyStore->occurrences[501]['requirements_text'], 'Duplication did not copy requisitions.');
        assertSameValue('Plan the experiment', $copyStore->occurrences[501]['planning_notes'], 'Duplication did not copy the lesson outline.');
        assertSameValue('Wear eye protection', $copyStore->occurrences[501]['risk_assessment_text'], 'Duplication did not copy the risk assessment.');
        assertSameValue('Bring goggles', $copyStore->occurrences[500]['requirements_text'], 'Duplication modified the source lesson.');
        assertSameValue('12CHEM', $copyStore->occurrences[501]['snapshot_class_code'], 'Duplication changed target class identity.');
        assertSameValue('LAB-B', $copyStore->occurrences[501]['snapshot_room_code'], 'Duplication changed target room identity.');
        assertSameValue('2026-09-08', $copyStore->occurrences[501]['lesson_date'], 'Duplication changed target date identity.');
        assertSameValue(1, $copyStore->occurrences[501]['snapshot_duration_periods'], 'Duplication changed target timetable duration.');
        assertSameValue('2026-09-08 07:30:00', $copyStore->occurrences[501]['prepared_at'], 'Duplication changed technician preparation metadata.');
        assertThrows(static fn () => (new TeacherPlanningService($copyStore))->duplicate(1, 10, 500, 502, new DateTimeImmutable('2026-09-07')), 'Cross-teacher duplication was accepted.');
        assertThrows(static fn () => (new TeacherPlanningService($copyStore))->duplicate(2, 10, 500, 501, new DateTimeImmutable('2026-09-07')), 'Cross-organisation duplication was accepted.');
        assertThrows(static fn () => (new TeacherPlanningService($copyStore))->duplicate(1, 10, 500, 503, new DateTimeImmutable('2026-09-07')), 'Cross-week service duplication was accepted.');
        assertThrows(static fn () => (new TeacherPlanningService($copyStore))->duplicate(1, 10, 504, 501, new DateTimeImmutable('2026-09-07')), 'A source without planning was allowed to clear a target.');

        $copyStore->occurrences[500]['state'] = 'nothing_required';
        $copyStore->occurrences[500]['requirements_text'] = 'Nothing required';
        $copyStore->occurrences[500]['planning_notes'] = 'Theory recap';
        $copyStore->occurrences[500]['risk_assessment_text'] = '';
        $confirmation = json_decode($copyPage->handle('POST', [], [
            'action' => 'duplicate', '_async' => '1', 'csrf_token' => CsrfToken::value(), 'date' => '2026-09-09',
            'source_occurrence_id' => 500, 'target_occurrence_id' => 501,
        ]), true);
        assertSameValue('confirmation_required', $confirmation['status'] ?? null, 'Populated target did not require overwrite confirmation.');
        assertSameValue('Bring goggles', $copyStore->occurrences[501]['requirements_text'], 'Unconfirmed duplication changed the target.');
        $oldRevision = (string) ($confirmation['target_revision'] ?? '');
        $copyStore->occurrences[501]['planning_notes'] = 'Concurrent edit';
        $stale = json_decode($copyPage->handle('POST', [], [
            'action' => 'duplicate', '_async' => '1', 'csrf_token' => CsrfToken::value(), 'date' => '2026-09-09',
            'source_occurrence_id' => 500, 'target_occurrence_id' => 501, 'overwrite' => 'yes', 'target_revision' => $oldRevision,
        ]), true);
        assertSameValue(true, $stale['target_changed'] ?? false, 'A concurrent target edit was not reported before overwrite.');
        assertSameValue('Concurrent edit', $copyStore->occurrences[501]['planning_notes'], 'A stale confirmation silently overwrote a concurrent edit.');
        $overwritten = json_decode($copyPage->handle('POST', [], [
            'action' => 'duplicate', '_async' => '1', 'csrf_token' => CsrfToken::value(), 'date' => '2026-09-09',
            'source_occurrence_id' => 500, 'target_occurrence_id' => 501, 'overwrite' => 'yes', 'target_revision' => $stale['target_revision'],
        ]), true);
        assertSameValue(true, $overwritten['ok'] ?? false, 'Confirmed overwrite did not succeed.');
        assertSameValue('nothing_required', $copyStore->occurrences[501]['state'], 'Explicit Nothing Required state was not copied.');
        assertSameValue('Nothing required', $copyStore->occurrences[501]['requirements_text'], 'Nothing Required requisition text was not copied.');
        assertSameValue('Theory recap', $copyStore->occurrences[501]['planning_notes'], 'Confirmed overwrite was not atomic across planning fields.');
        $repeated = json_decode($copyPage->handle('POST', [], [
            'action' => 'duplicate', '_async' => '1', 'csrf_token' => CsrfToken::value(), 'date' => '2026-09-09',
            'source_occurrence_id' => 500, 'target_occurrence_id' => 501, 'overwrite' => 'yes', 'target_revision' => $oldRevision,
        ]), true);
        assertSameValue('confirmation_required', $repeated['status'] ?? null, 'A repeated stale overwrite submission was not safely rejected.');
        $self = json_decode($copyPage->handle('POST', [], [
            'action' => 'duplicate', '_async' => '1', 'csrf_token' => CsrfToken::value(), 'date' => '2026-09-09',
            'source_occurrence_id' => 500, 'target_occurrence_id' => 500,
        ]), true);
        assertSameValue(false, $self['ok'] ?? true, 'Self-duplication was accepted.');
        $outside = json_decode($copyPage->handle('POST', [], [
            'action' => 'duplicate', '_async' => '1', 'csrf_token' => CsrfToken::value(), 'date' => '2026-09-16',
            'source_occurrence_id' => 500, 'target_occurrence_id' => 501,
        ]), true);
        assertSameValue(false, $outside['ok'] ?? true, 'Cross-week duplication was accepted.');
        $invalidTarget = json_decode($copyPage->handle('POST', [], [
            'action' => 'duplicate', '_async' => '1', 'csrf_token' => CsrfToken::value(), 'date' => '2026-09-09',
            'source_occurrence_id' => 500, 'target_occurrence_id' => 999,
        ]), true);
        assertSameValue(false, $invalidTarget['ok'] ?? true, 'Duplication into an empty timetable slot was accepted.');
        $failedCsrf = json_decode($copyPage->handle('POST', [], [
            'action' => 'duplicate', '_async' => '1', 'csrf_token' => 'invalid', 'date' => '2026-09-09',
            'source_occurrence_id' => 500, 'target_occurrence_id' => 501,
        ]), true);
        assertSameValue(false, $failedCsrf['ok'] ?? true, 'A failed duplication request was reported as successful.');
        $weekScript = (string) file_get_contents(__DIR__ . '/../public/assets/teacher-week.js');
        assertContainsValue("event.pointerType !== 'mouse'", $weekScript, 'Touch interaction did not distinguish long-press initiation.');
        assertContainsValue('}, 500)', $weekScript, 'Mobile long press did not use a deliberate hold threshold.');
        assertContainsValue("distance >= 6", $weekScript, 'Desktop drag did not preserve click-versus-drag movement distinction.');
        assertContainsValue("distance > 10", $weekScript, 'Touch swipe did not retain a movement threshold before long-press activation.');
        assertContainsValue("event.preventDefault()", $weekScript, 'Active dragging did not suppress browser movement behaviour.');
        assertContainsValue("addEventListener('contextmenu'", $weekScript, 'Lesson tiles did not suppress their native long-press context menu.');
        assertContainsValue("addEventListener('selectstart'", $weekScript, 'Lesson tiles did not suppress native text selection initiation.');
        assertContainsValue("addEventListener('touchmove'", $weekScript, 'Active touch dragging did not install a non-passive movement guard.');
        assertContainsValue("{ passive: false }", $weekScript, 'Active touch movement could not be cancelled.');
        assertContainsValue("window.setTimeout(function () { suppressClick = false; }, 700)", $weekScript, 'Completed or cancelled drags did not suppress delayed mobile clicks.');
        assertContainsValue("if (busy", $weekScript, 'Duplicate submissions were not guarded while a request is active.');
        assertContainsValue("target.dataset.requisitions", $weekScript, 'Successful duplication did not update the target tile in place.');
        assertContainsValue('.week-grid .lesson-block {', $css, 'Native-interaction suppression was not scoped to Week View lesson tiles.');
        assertContainsValue('-webkit-touch-callout: none;', $css, 'Week View lesson tiles did not disable iOS touch callouts.');
        assertContainsValue('-webkit-user-drag: none;', $css, 'Week View lesson tiles did not disable browser-native link dragging.');
        assertContainsValue('-webkit-user-select: none;', $css, 'Week View lesson tiles did not disable WebKit text selection.');
        assertContainsValue('touch-action: pan-x pan-y;', $css, 'Lesson tiles did not preserve native horizontal and vertical timetable panning.');
        assertNotContainsValue('textarea { -webkit-user-select: none;', $css, 'Text-selection suppression leaked into lesson editor textareas.');
    }
}

function assertContainsValue(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) throw new \RuntimeException($message);
}

function assertNotContainsValue(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle)) throw new \RuntimeException($message);
}

/** @param list<string> $headings */
function assertColumnHeadings(string $html, array $headings, string $message): void
{
    $position = -1;
    foreach ($headings as $heading) {
        $next = strpos($html, '>' . $heading . '</th>', $position + 1);
        if ($next === false || $next < $position) throw new \RuntimeException($message);
        $position = $next;
    }
}

final class TeacherStore implements TeacherPlanningStore
{
        public array $occurrences = [500 => [
        'id' => 500, 'lesson_date' => '2026-09-07', 'timetable_version_id' => 1, 'snapshot_teacher_user_id' => 10,
        'class_id' => 301,
        'snapshot_class_code' => '13PHY', 'snapshot_room_code' => 'LAB-A', 'snapshot_start_slot_id' => 101,
        'snapshot_duration_periods' => 2, 'day_of_week' => 1, 'teaching_period_number' => 1, 'slot_label' => 'Period One',
        'state' => 'not_completed', 'requirements_text' => 'Bring goggles', 'planning_notes' => 'Plan the experiment',
        'risk_assessment_text' => 'Wear eye protection',
    ]];
    public TimetableVersion $version;
    /** @var list<TimetableSlot> */
        public array $slots = [];
    public bool $ensured = false;
    public bool $noClasses = false;
    public int $firstDay = 1;
    /** @var list<array{id:int,code:string}> */
    public array $classes = [['id' => 301, 'code' => '13PHY']];
    /** @var array{int,int,int}|null */
    public ?array $lastRangeScope = null;

    public function __construct()
    {
        $this->version = new TimetableVersion(1, 1, 'Autumn timetable', new DateTimeImmutable('2026-09-01'), null);
        $this->slots = [
            new TimetableSlot(101, 1, 1, 1, 'teaching', 1, 'Period One'),
            new TimetableSlot(102, 1, 1, 2, 'teaching', 2, 'Period Two'),
            new TimetableSlot(103, 1, 1, 3, 'break', null, 'Break'),
            new TimetableSlot(104, 1, 1, 4, 'teaching', 3, 'Period Three'),
            new TimetableSlot(105, 1, 1, 5, 'lunch', null, 'Lunch'),
            new TimetableSlot(201, 1, 2, 1, 'teaching', 1, 'Period One'),
        ];
    }

    public function teacherBelongsToOrganisation(int $teacherId, int $organisationId): bool { return $teacherId === 10 && $organisationId === 1; }
    public function classesForTeacher(int $organisationId, int $teacherId): array { return !$this->noClasses && $organisationId === 1 && $teacherId === 10 ? $this->classes : []; }
    public function activeFirstDayOfWeek(int $organisationId): int { return $this->firstDay; }
    public function effectiveVersion(int $organisationId, DateTimeImmutable $date): ?TimetableVersion { return $organisationId === 1 && $date >= $this->version->effectiveFrom ? $this->version : null; }
    public function ensureOccurrencesForWeek(int $organisationId, DateTimeImmutable $start, DateTimeImmutable $end): void { $this->ensured = true; }
    public function ensureOccurrencesForRange(int $organisationId, DateTimeImmutable $start, DateTimeImmutable $end, ?int $teacherId = null, ?int $classId = null): void { $this->ensured = true; $this->lastRangeScope = [$organisationId, (int) $teacherId, (int) $classId]; }
    public function slotsForVersion(int $versionId): array { return $this->slots; }
    public function occurrencesForTeacherDate(int $organisationId, int $teacherId, DateTimeImmutable $date): array { return $organisationId !== 1 ? [] : array_values(array_filter($this->occurrences, static fn (array $o): bool => $o['lesson_date'] === $date->format('Y-m-d') && (int) $o['snapshot_teacher_user_id'] === $teacherId)); }
    public function occurrencesForTeacherClass(int $organisationId, int $teacherId, int $classId, DateTimeImmutable $start, DateTimeImmutable $end, int $limit, bool $descending = false): array { $rows = array_values(array_filter($this->occurrences, static fn (array $o): bool => (int) ($o['class_id'] ?? 0) === $classId && $o['lesson_date'] >= $start->format('Y-m-d') && $o['lesson_date'] <= $end->format('Y-m-d'))); usort($rows, static fn (array $a, array $b): int => [$a['lesson_date'], $a['teaching_period_number'], $a['id']] <=> [$b['lesson_date'], $b['teaching_period_number'], $b['id']]); if ($descending) $rows = array_reverse($rows); return array_slice($rows, 0, $limit); }
    public function findOccurrenceForTeacher(int $organisationId, int $teacherId, int $occurrenceId): ?array { $row = $this->occurrences[$occurrenceId] ?? null; return $row !== null && $organisationId === 1 && $teacherId === (int) $row['snapshot_teacher_user_id'] ? $row : null; }
    public function savePlanning(int $occurrenceId, string $state, string $lessonOutline, string $requisitions, string $riskAssessment): void { $this->occurrences[$occurrenceId]['state'] = $state; $this->occurrences[$occurrenceId]['planning_notes'] = $lessonOutline; $this->occurrences[$occurrenceId]['requirements_text'] = $requisitions; $this->occurrences[$occurrenceId]['risk_assessment_text'] = $riskAssessment; }
    public function savePlanningSection(int $occurrenceId, string $section, string $value, bool $nothingRequired): array { if ($section === 'outline') $this->occurrences[$occurrenceId]['planning_notes'] = $value; elseif ($section === 'risk') $this->occurrences[$occurrenceId]['risk_assessment_text'] = $value; else { $this->occurrences[$occurrenceId]['requirements_text'] = $value; $this->occurrences[$occurrenceId]['state'] = $nothingRequired || $value === 'Nothing required' ? 'nothing_required' : ($value === '' ? 'not_completed' : 'requirements_entered'); } return ['value' => $value, 'nothing_required' => ($this->occurrences[$occurrenceId]['state'] ?? '') === 'nothing_required']; }
    public function duplicatePlanning(int $organisationId, int $teacherId, int $sourceOccurrenceId, int $targetOccurrenceId, DateTimeImmutable $weekStart, bool $overwrite, string $expectedTargetRevision): array { $source = $this->findOccurrenceForTeacher($organisationId, $teacherId, $sourceOccurrenceId); $target = $this->findOccurrenceForTeacher($organisationId, $teacherId, $targetOccurrenceId); if ($source === null || $target === null) throw new \Reqsheet\Timetable\TimetableValidationException(['Both lessons must belong to you in the displayed week.']); $end = $weekStart->modify('+6 days')->format('Y-m-d'); foreach ([$source, $target] as $lesson) if ($lesson['lesson_date'] < $weekStart->format('Y-m-d') || $lesson['lesson_date'] > $end) throw new \Reqsheet\Timetable\TimetableValidationException(['Lessons can only be copied within the displayed week.']); if (!TeacherPlanningService::planningPopulated($source)) throw new \Reqsheet\Timetable\TimetableValidationException(['The source lesson has no planning information to copy.']); $revision = TeacherPlanningService::planningRevision($target); if (!$overwrite && TeacherPlanningService::planningPopulated($target)) return ['status' => 'confirmation_required', 'target_revision' => $revision, 'target_changed' => false]; if ($overwrite && ($expectedTargetRevision === '' || !hash_equals($revision, $expectedTargetRevision))) return ['status' => 'confirmation_required', 'target_revision' => $revision, 'target_changed' => true]; foreach (['state', 'requirements_text', 'planning_notes', 'risk_assessment_text'] as $field) $this->occurrences[$targetOccurrenceId][$field] = $source[$field] ?? null; $copied = $this->occurrences[$targetOccurrenceId]; return ['status' => 'copied', 'target_id' => $targetOccurrenceId, 'state' => (string) ($copied['state'] ?? 'not_completed'), 'requisitions' => (string) ($copied['requirements_text'] ?? ''), 'outline' => (string) ($copied['planning_notes'] ?? ''), 'risk' => (string) ($copied['risk_assessment_text'] ?? ''), 'nothing_required' => ($copied['state'] ?? '') === 'nothing_required', 'populated' => true, 'target_revision' => TeacherPlanningService::planningRevision($copied)]; }
}
