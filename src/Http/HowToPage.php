<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class HowToPage
{
    public static function render(string $section, array $user): string
    {
        $allowed = ['teacher' => SessionAuth::hasRole($user, 'teacher'), 'technician' => SessionAuth::hasRole($user, 'technician'), 'administrator' => SessionAuth::isAdmin($user)];
        if ($section !== '' && !($allowed[$section] ?? false)) {
            http_response_code(403);
            return PageLayout::render('How-to', '<section class="content-narrow"><h1>How-to</h1><p class="notice error">This guide is not available for your role.</p></section>', $user);
        }
        $image = static fn (string $name, string $alt): string => '<figure class="how-to-figure"><img loading="lazy" src="/assets/screenshots/demo/' . rawurlencode($name) . '" alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></figure>';
        $links = '<nav class="how-to-sections" aria-label="How-to sections"><p>Choose a guide:</p><ul>' . self::sectionLink('teacher', 'Teacher', $allowed['teacher']) . self::sectionLink('technician', 'Technician', $allowed['technician']) . self::sectionLink('administrator', 'Administrator', $allowed['administrator']) . '</ul></nav>';
        $content = '<article class="how-to content-wide"><header class="showcase-intro"><p class="eyebrow">Practical guides</p><h1>How-to</h1><p>Short, task-focused instructions for using Reqsheet in your department.</p></header>' . $links;
        if ($section === 'teacher') $content .= self::teacher($image);
        elseif ($section === 'technician') $content .= self::technician($image);
        elseif ($section === 'administrator') $content .= self::administrator($image);
        else $content .= '<section class="how-to-overview"><h2>Start with your role</h2><p>Select the guide that matches the work you need to do. Accounts with more than one role can use each permitted guide.</p></section>';
        return PageLayout::render('How-to' . ($section !== '' ? ' · ' . ucfirst($section) : ''), $content, $user);
    }

    private static function sectionLink(string $section, string $label, bool $allowed): string
    {
        return '<li>' . ($allowed ? '<a href="/how-to/' . $section . '">' . $label . '</a>' : '<span class="nav-disabled" aria-disabled="true">' . $label . '</span>') . '</li>';
    }

    private static function teacher(callable $image): string
    {
        return '<section class="how-to-section"><p class="eyebrow">Teacher</p><h2>Week View</h2><p>Open <a href="/teacher">View My Timetable</a> and find the lesson by day and period. Select the lesson tile to open its lesson editor.</p><ol class="how-to-steps"><li>Enter or update the lesson outline.</li><li>Enter requisitions in the requisitions field. If no equipment is needed, select <strong>Nothing required</strong>.</li><li>Add the risk assessment where one is needed.</li><li>Save the planning. The updated requisition summary appears in the timetable tile.</li></ol><p>Open the same lesson again whenever you need to review or edit its planning. The editor preserves the lesson’s timetable assignment.</p>' . $image('teacher-week-view.png', 'Teacher Week View lesson tiles with planning summaries.') . '<h2>Day View</h2><p>Use <a href="/teacher/day">Day View</a> to choose a date and read that day’s lessons, requisitions, outlines and risk assessments. Use the existing editing controls in each planning cell when you need to make a change.</p>' . $image('teacher-day-view.png', 'Teacher Day View with a date picker and planning columns.') . '<h2>Class View</h2><p>Use <a href="/teacher/class">Class View</a> to choose a class. It shows the three most recent previous lessons, today and the nearest upcoming lessons, so you can review completed planning and prepare what is next.</p>' . $image('teacher-class-view.png', 'Teacher Class View showing previous and upcoming lessons.') . '</section>';
    }

    private static function technician(callable $image): string
    {
        return '<section class="how-to-section"><p class="eyebrow">Technician</p><h2>Day View and room selection</h2><ol class="how-to-steps"><li>Open <a href="/technician">Technician Day View</a> and use the arrows or Today control to choose a date.</li><li>Tick the rooms you want to see, or use Select all and Clear all, then choose Apply display.</li><li>Read each room’s requisitions by teaching period. A lesson with details can be opened from its cell.</li><li>Review the lesson details and select <strong>Mark as Prepped</strong> when preparation is complete.</li></ol>' . $image('technician-day-view.png', 'Technician Day View with room checkboxes and requisition cells.') . $image('technician-lesson-details.png', 'Technician lesson details with Mark as Prepped.') . '<h2>Printing</h2><p>Use Print Selected Day for the displayed date or Print Selected Week for the weekly preparation sheets. Adjust the room selection and printer layout options before printing when necessary.</p></section>';
    }

    private static function administrator(callable $image): string
    {
        return '<section class="how-to-section"><p class="eyebrow">Administrator</p><h2>Initial setup</h2><p>Complete the organisation settings and timetable template before normal use. Set the working days, first day of the working week, periods and any breaks or separators.</p>' . $image('admin-settings-timetable-template.png', 'Administrator timetable template settings.') . '<h2>People management</h2><p>Open <a href="/admin/people">People</a> to add or edit staff. Use three-capital-letter initials, assign the Teacher and/or Technician role, and assign Administrator where appropriate.</p>' . $image('admin-people.png', 'Administrator People page with staff initials and roles.') . '<h2>Timetable creation and CSV import</h2><ol class="how-to-steps"><li>Configure the timetable template’s working days and periods.</li><li>Download the CSV template.</li><li>Complete the CSV using school timetable information.</li><li>Upload the CSV and use validation and preview to resolve reported errors.</li><li>Review and edit the imported timetable, then activate the version where relevant.</li></ol><p>CSV import is deterministic and validates the uploaded rows; it is not a direct MIS integration.</p><p>Handling timetable imports from a wide variety of Management Information Systems (MIS) is challenging. The process depends on how each MIS exports its data, and the consistency of that output is outside our control. Manually reformatting an exported timetable can sometimes take longer than entering the timetable directly into Reqsheet.</p><p>Our importer follows a clear and specific set of rules. The easiest approach is to download your Reqsheet CSV template, export your department timetable from your MIS, and ask an AI tool (such as ChatGPT or Claude) to populate the Reqsheet template using your exported information.</p><p>If any rooms or people are missing during import, you can add them with a single click.</p><p><strong>Typically takes less than 5 minutes.</strong></p><div class="how-to-image-grid">' . $image('admin-csv-import.png', 'Administrator CSV import and validation form.') . $image('admin-timetable-editor.png', 'Administrator timetable editor for reviewing and editing lessons.') . '</div><h2>Timetable editing and management</h2><p>Use <a href="/admin/timetable">Timetable</a> to select a version, staff member or class view, edit lesson assignments, manage rooms and classes, and create or activate effective-dated timetable versions. Existing dated lesson records remain associated with their timetable history.</p></section>';
    }
}
