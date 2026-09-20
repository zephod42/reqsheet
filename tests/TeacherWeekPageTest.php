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
        assertContainsValue('/teacher/day?date=2026-09-07', $view, 'Week day headings did not link to the teacher day view.');
        assertContainsValue('today-row', $view, 'Current day was not gently highlighted.');
        assertSameValue(true, $store->ensured, 'Teacher week did not prepare effective recurring assignments for the selected week.');
        $css = (string) file_get_contents(__DIR__ . '/../public/assets/app.css');
        assertContainsValue('.period-label { width: 1%;', $css, 'Teacher period column was not narrowed.');
        assertContainsValue('text-align: center; vertical-align: middle; white-space: nowrap;', $css, 'Teacher period labels were not centred without truncation.');
        assertContainsValue('.day-label { padding: .6rem; text-align: center; vertical-align: middle;', $css, 'Teacher day headings were not centred.');

        $store->firstDay = 3;
        $alternateWeek = new TeacherWeekPage(new TeacherPlanningService($store), 1, 10, new DateTimeImmutable('2026-09-23'), ['staff_identifier' => 'NEV']);
        assertContainsValue('Week beginning Wednesday 23 September 2026', $alternateWeek->handle('GET', ['date' => '2026-09-23'], []), 'Week heading ignored the configured first day.');
        assertContainsValue('Week beginning Wednesday 30 September 2026', $alternateWeek->handle('GET', ['date' => '2026-09-30'], []), 'Future week heading did not follow navigation selection.');
        $store->firstDay = 1;

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
        assertContainsValue('1–2', $day, 'Multi-period lesson did not appear as a single period range.');
        assertContainsValue('Plan the experiment', $day, 'Day view did not show the complete lesson outline.');
        assertContainsValue('Bring goggles', $day, 'Day view did not show the complete requisition text.');
        assertContainsValue('Wear eye protection', $day, 'Day view did not show the complete risk assessment.');
        assertContainsValue('name="section" value="outline"', $day, 'Day view did not provide inline outline editing.');
        assertContainsValue('name="section" value="requisitions"', $day, 'Day view did not provide inline requisition editing.');
        assertContainsValue('name="section" value="risk"', $day, 'Day view did not provide inline risk editing.');
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

        $foreign = new TeacherWeekPage(new TeacherPlanningService($store), 2, 10, new DateTimeImmutable('2026-09-09'));
        assertContainsValue('Teacher does not belong to the requested organisation.', $foreign->handle('GET', [], []), 'Foreign organisation was not rejected.');
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

final class TeacherStore implements TeacherPlanningStore
{
        public array $occurrences = [500 => [
        'id' => 500, 'lesson_date' => '2026-09-07', 'timetable_version_id' => 1, 'snapshot_teacher_user_id' => 10,
        'snapshot_class_code' => '13PHY', 'snapshot_room_code' => 'LAB-A', 'snapshot_start_slot_id' => 101,
        'snapshot_duration_periods' => 2, 'day_of_week' => 1, 'teaching_period_number' => 1, 'slot_label' => 'Period One',
        'state' => 'not_completed', 'requirements_text' => 'Bring goggles', 'planning_notes' => 'Plan the experiment',
        'risk_assessment_text' => 'Wear eye protection',
    ]];
    public TimetableVersion $version;
    /** @var list<TimetableSlot> */
        public array $slots = [];
    public bool $ensured = false;
    public int $firstDay = 1;

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
    public function activeFirstDayOfWeek(int $organisationId): int { return $this->firstDay; }
    public function effectiveVersion(int $organisationId, DateTimeImmutable $date): ?TimetableVersion { return $organisationId === 1 && $date >= $this->version->effectiveFrom ? $this->version : null; }
    public function ensureOccurrencesForWeek(int $organisationId, DateTimeImmutable $start, DateTimeImmutable $end): void { $this->ensured = true; }
    public function slotsForVersion(int $versionId): array { return $this->slots; }
    public function occurrencesForTeacherDate(int $organisationId, int $teacherId, DateTimeImmutable $date): array { return array_values(array_filter($this->occurrences, static fn (array $o): bool => $o['lesson_date'] === $date->format('Y-m-d'))); }
    public function findOccurrenceForTeacher(int $organisationId, int $teacherId, int $occurrenceId): ?array { return $this->occurrences[$occurrenceId] ?? null; }
    public function savePlanning(int $occurrenceId, string $state, string $lessonOutline, string $requisitions, string $riskAssessment): void { $this->occurrences[$occurrenceId]['state'] = $state; $this->occurrences[$occurrenceId]['planning_notes'] = $lessonOutline; $this->occurrences[$occurrenceId]['requirements_text'] = $requisitions; $this->occurrences[$occurrenceId]['risk_assessment_text'] = $riskAssessment; }
}
