<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class DemoPage
{
    public static function render(?array $user = null): string
    {
        $image = static fn (string $name, string $alt, string $class = ''): string => '<figure class="demo-figure ' . $class . '"><img loading="lazy" src="/assets/screenshots/demo/' . rawurlencode($name) . '" alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></figure>';
        $body = '<article class="demo-page showcase content-wide">'
            . '<header class="showcase-intro"><p class="eyebrow">A quick tour</p><h1>See Reqsheet in action</h1><p>Reqsheet keeps lesson planning, preparation and timetable management in one clear shared view.</p></header>'
            . '<section class="showcase-section"><div class="showcase-section-heading"><p class="eyebrow">Teacher</p><h2>Plan lessons without the paperwork</h2></div>'
            . '<div class="showcase-feature">' . $image('teacher-week-view.png', 'Teacher Week View showing a teaching week with lesson planning information.', 'demo-main-image') . '<div><h3>Your teaching week at a glance</h3><p>See your teaching week, identify lessons and update requirements directly from the timetable.</p><p>Click directly into any lesson cell to open the lesson editor, then update requisitions, lesson outlines, or risk assessments without leaving the week view. Fast, simple and clear.</p></div></div>'
            . '<div class="showcase-secondary-grid"><div><h3>Everything you need for the day</h3><p>Day View gathers each lesson\'s requisitions, outline and risk assessment for a chosen date.</p>' . $image('teacher-day-view.png', 'Teacher Day View listing lessons, requisitions, lesson outlines and risk assessments.') . '</div><div><h3>Plan ahead and look back</h3><p>Class View brings recent lessons and the nearest upcoming lessons together for a selected class.</p>' . $image('teacher-class-view.png', 'Teacher Class View showing recent and upcoming lessons for a class.') . '</div></div></section>'
            . '<section class="showcase-section"><div class="showcase-section-heading"><p class="eyebrow">Technician</p><h2>See what needs preparing, where and when</h2></div>'
            . '<div class="showcase-feature">' . $image('technician-day-view.png', 'Technician Day View showing rooms, periods and lesson requisitions.', 'demo-main-image') . '<div><h3>A practical preparation view</h3><p>The Technician Day View brings requisitions together by room and teaching period, so the department can work from one daily list.</p></div></div>'
            . '<div class="showcase-secondary-grid"><div><h3>Open lesson details</h3><p>Select a lesson to read its requirements and use the existing <strong>Mark as Prepped</strong> control when preparation is complete.</p>' . $image('technician-lesson-details.png', 'Technician lesson details with the Mark as Prepped control.') . '</div><div><h3>Choose the rooms you need</h3><p>Select the usual rooms, or adjust the display to include the wider department when required.</p>' . $image('technician-day-view.png', 'Technician Day View room selection controls and timetable.', 'demo-crop-context') . '</div></div><p class="showcase-note"><strong>Printing:</strong> Technician views provide daily and weekly print actions for practical lesson preparation.</p></section>'
            . '<section class="showcase-section"><div class="showcase-section-heading"><p class="eyebrow">Administrator</p><h2>Set up once, then keep the timetable current</h2></div>'
            . '<div class="showcase-feature admin-feature">' . $image('admin-settings-timetable-template.png', 'Administrator settings showing working days and timetable periods.', 'demo-main-image') . '<div><h3>Configure the timetable template</h3><p>Choose working days and teaching periods, then save the template that matches your department.</p><ol class="showcase-steps"><li>Configure the timetable template, including working days and teaching periods.</li><li>Download the Reqsheet CSV template.</li><li>Complete the CSV using the school’s timetable information.</li><li>Upload the completed CSV to Reqsheet.</li><li>Review and edit the timetable in the application.</li></ol><p>The CSV can be populated from existing school timetable data. Reqsheet does not provide direct MIS integration.</p></div></div>'
            . '<div class="showcase-admin-images"><div>' . $image('admin-csv-import.png', 'Administrator CSV import form with validation and preview.', 'demo-secondary-image') . '</div><div>' . $image('admin-timetable-editor.png', 'Administrator timetable editor for reviewing and editing lessons.', 'demo-secondary-image') . '</div></div>'
            . '<div class="showcase-people"><div><h3>Add and manage teachers, technicians, and admins with ease.</h3><p>People keeps staff roles and initials organised for the whole organisation.</p></div>' . $image('admin-people.png', 'Administrator People page showing staff initials and roles.', 'demo-secondary-image') . '</div></section></article>';
        return PageLayout::render('See Reqsheet in action', $body, $user);
    }
}
