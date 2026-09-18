# Product design

## Purpose

Reqsheet will help a science department prepare, review, and print lesson requisitions in a consistent format.

## Initial principles

- Keep the workflow understandable to non-technical school staff.
- Prefer server-rendered HTML and conventional forms.
- Make printed requisitions clear and reliable on ordinary office printers.
- Keep data ownership, validation, and permissions explicit.
- Avoid product scope that is not required by the department's workflow.

## Agreed UI and product direction (2026-09-18)

### Public landing and authentication

The public landing page is deliberately simple: `Reqsheet.` appears as large, simple black text on a white background. The full stop is deliberate branding and there is no tagline. Login fields appear below the wordmark. A simple left-side navigation contains About, Demo, Sign up, and Contact. Clicking the wordmark anywhere returns to the main landing/home page.

Pilot authentication should support a persistent/remembered login so ordinary users are not repeatedly prompted for a password during normal daily use. The exact session lifetime and security policy remain part of the later account-security pass. Pilot sign-up must not require email; sign-up creates a new organisation/school and its first Admin account.

### Setup gating

If required organisation settings are incomplete, an Admin account lands directly on Settings/setup. A non-Admin account sees a centred blocking state rather than an empty or broken application screen:

> Something's missing...
>
> Settings need to be configured. Contact your admin.

This gate applies consistently wherever normal application use would otherwise be unavailable because setup is incomplete.

### Visual direction

The overall appearance is intentionally restrained: white background, black text, and minimal decoration. Reqsheet-owned navigation, menus, buttons, and other UI chrome use a clear, slightly bold sans-serif. Operational timetable and requisition data uses a visually distinct technical/data-oriented font, with Codex-like typography as the reference feel. Tables use thin black rules, with selectively heavier rules for major boundaries such as periods, days, or week structure.

## Current database boundary

The initial application-domain schema now covers organisations, staff identities, effective-dated timetable configuration, recurring lessons, dated lesson occurrences, and requisitions. Application services provide the validated path for timetable versions, slots, and recurring lessons; a bounded, explicit-date occurrence-generation service validates timetable spans and conflicts before inserting historical occurrences. A skeletal, temporarily protected admin timetable editor now uses those services for version creation and recurring-lesson creation/editing/removal before historical occurrences exist. A skeletal teacher current-week view now reads dated occurrences and saves the three planning text fields without changing recurring lessons. Pilot login, first-run setup, sessions, and role/admin checks now provide the minimum browser access path; the project still contains no technician timetable UI, printing implementation, or application-domain catalogues.

## Operational roles and landing pages

The two operational roles are Teacher and Technician. Admin is an additional permission, not a separate operational role. No Head of Subject role is required.

- A Teacher lands on their current week.
- A Technician lands on the current day.
- Teacher+Admin uses the teacher week landing page; Technician+Admin uses the technician day landing page.
- Admin configuration and timetable tools are available through the additional Admin permission.

The current login and access checks are deliberately pilot-grade; this section describes the agreed product navigation and landing behaviour.

## Teacher week view

The teacher view is week-oriented. Working days run down the left and teaching periods run across the top. Monday is the default first day of the week, with the configured working-day set controlling which days are shown.

The heading is “Week beginning <Monday date>”, with previous/next navigation, a “This week” control, and a “Select week” calendar/date-picker popout for jumping directly to a week. These quick navigation controls complement one another. When viewing the current week, the current day is gently highlighted.

Break and lunch appear as narrow grey separator columns in the grid rather than lesson-bearing timetable cells. This is a display decision: the underlying timetable configuration may still contain non-teaching interval records so existing span validation remains authoritative.

Each lesson tile has a header bar with the class code on the left and room on the right. The body displays only the free-text Requisitions content. Tiles have a fixed size; overflowing content is truncated with an ellipsis, with the full text available on click and optionally on hover.

Each distinct class is assigned a light pastel background colour in the spirit of Google Calendar, and the same class colour is used consistently. This treatment is primarily for the teacher timetable; the technician on-screen view remains neutral. Technician print output remains black-and-white.

The lesson block top strip/header makes the class code prominent and bold on the left and the room code prominent on the right. Both use the technical/data font. A slightly heavier horizontal rule sits beneath the strip; unnecessary text colours are not added. The block below the header remains visually quiet.

Only requisition free text appears directly in the teacher weekly planner, and it is the same content technicians need to see. Lesson outline and risk assessment remain inside the lesson editing UI.

Clicking or tapping a lesson block opens a large pop-out, approximately the full available screen width. It is neither an inline expansion nor a separate page. The pop-out contains three editable, optional areas: Lesson outline, Requisitions, and Risk assessment. Requisitions may genuinely remain blank. An explicit `Nothing required` checkbox/action sits beneath the requisitions area; selecting it populates the requisitions free-text field with `Nothing required`. This state is not pre-filled by default.

## Teacher day view

Teachers can open a dedicated day view by clicking a day/date heading in the week view or using a prominent “Today’s lessons” control. The selected day/date is shown at the top, with previous/next day navigation and a “Select day” calendar/date-picker popout for jumping directly to a date.

The day view is a vertically ordered working screen. Teaching periods run down the left. Break, lunch, and other configured non-teaching intervals appear at their chronological positions as clear separator rows rather than lesson rows. Normal teacher use shows a configured period label such as P1, P2, or P3, not start/end times. Tapping a lesson uses the same large lesson-editing pop-out as the week view.

Each day lesson shows its period, room, class code, and requisition context; the three planning fields are edited in the lesson pop-out, without opening a separate detail page. The week view remains compact and overview-oriented; this day view is the more detailed planning screen.

## Teacher class planning view

Class codes in teacher week and day views are clickable navigation links. Clicking one opens a chronological view of that class’s dated lesson occurrences with columns for Date, Day / Period (for example, “Mon P4”), Lesson outline, Requisitions, and Risk assessment. These three planning fields are edited through the lesson pop-out for the selected occurrence.

When opened from a particular lesson occurrence, the class view positions that occurrence approximately in the middle of the viewport. The teacher can scroll upward to earlier lessons and downward to later or future lessons, gaining historical and forward planning context without restarting at the beginning of the class history.

The class view is based on dated lesson occurrences and history, not only the current recurring timetable. Week, day, and class views are complementary views of the same occurrence/requisition records, so edits are reflected across all three and historical lessons remain meaningful after timetable changes.

Conceptually: week view answers “What am I teaching this week?”, day view answers “What am I teaching/preparing today?”, and class view answers “Where am I with this class, what did we do previously, and what comes next?”.

## Technician day and inspection views

The technician view is day-oriented. Rooms/labs run across the top and teaching periods run down the left. Break and lunch appear as narrow grey separator rows. Technician cells display only free-text Requisitions; teacher and class context is available where needed to identify a lesson, but the cell’s requisition content is not duplicated or re-entered.

The day heading has previous/next day controls and a Today control. A technician may save a personal default subset of rooms for the landing view, switch easily between My rooms, All rooms, and a custom room selection, and still access every room. Personal room preferences are not a permission boundary.

Technicians can also inspect a selected teacher’s week or a selected room’s week. These are views of the same dated lessons and requisitions, not separate technician-entered records.

## Printing

Printing is a core technician workflow. The system must support printing a future week, commonly the following week’s requisition sheets. The canonical output is one printed A4 page per day, with periods down the left and one column for each selected room/lab. The layout should reduce spacing, font size, and column widths where practical to keep the selected rooms on one daily page.

Printing supports My rooms, All rooms, or a custom room selection. It is derived from the same dated lesson and requisition records as the technician day view; it does not create duplicate technician data.

## Admin configuration

Admin configuration should remain straightforward and editable. The first-run Settings/setup screen must collect the following before normal use:

- School name.
- Working days, defaulting to Monday-Friday.
- First day of the working week, defaulting to Monday.
- Periods per day, defaulting to six.
- At least one room; no room is defaulted or pre-filled.

The same screen also offers optional settings:

- A general start time and standard period length.
- A `Custom day` facility so individual days may use different timings or period lengths.
- Separators/breaks with type Break, Lunchtime, or Other, the periods between which they occur, and an optional duration.
- An `Allow double periods` toggle, disabled by default. Its help text explains that adjoining periods with the same class code and room code are combined when enabled. Configured breaks, lunch, and other separators prevent merging across them.

The timetable structure remains an ordered editor that can add, move, and rename periods and separators. Each teaching period has a configurable label/name, order, start time, and end time; separators have configurable names, timing, and order. Admins can manage rooms/labs by adding, renaming, reordering, and deactivating/archiving them.
- Manage people by creating a Teacher or Technician. The person’s name is used as the login name; an optional abbreviation such as JSM may be recorded; Admin is an optional additional permission.

The first pilot may create an account with no password until its first login, when the user sets one. This must be represented explicitly as awaiting-first-login, not inferred from a blank password, and is a consciously temporary security weakness requiring review before broader production deployment.

## Timetable versions and editor

Timetables are effective-dated versions. Each version has a label/name and effective start date; a later version supersedes an earlier version from its start date. The design supports mid-year revisions and future academic-year timetables being entered and edited in advance. Historical versions are preserved, and dated occurrences retain their historical snapshots.

An admin can copy/clone an existing timetable as the basis of a new version. “Edit timetable” opens a clickable timetable maker. Viewing and editing can be organised by staff member, room, or day, with staff-member view as the default. Lesson entry remains simple: class code, room, and period/span. The configured structure drives teacher week/day layouts, separator positioning, occurrence generation, chronological ordering, determination of true period adjacency, and double/multi-period merge eligibility. Start/end times are stored even when normal teacher day labels show only the period name. The existing service-level timetable validation rules remain authoritative for all entry paths: a multi-period lesson may cross only contiguous teaching periods and may not cross any configured break, lunch, or other non-teaching interval; periods separated by such an interval are separate lesson blocks.

## Canonical timetable CSV import/export

Reqsheet supports one canonical Reqsheet CSV format. It does not implement SIMS, Arbor, iSAMS, or other MIS-specific parsers.

The intended workflow is:

1. Build the timetable structure in Reqsheet.
2. Export a Reqsheet CSV template.
3. Populate that template externally.
4. Import the completed canonical CSV.
5. Deterministically validate and show a preview.
6. Commit only after confirmation.

Import validation covers the exact CSV schema, days and periods, known teachers, known rooms, timetable-version context, duplicates and conflicts, and valid contiguous lesson spans. The importer must not infer arbitrary file formats, heuristically strip content, guess with regular expressions, or call an AI API.

The UI should include help titled “Using AI to help with timetable import”. It explains that a user may upload the Reqsheet CSV template and their MIS export to an external AI assistant and ask that assistant to populate the Reqsheet structure. AI conversion is external to Reqsheet. The help may provide this copyable prompt:

```text
Populate the attached Reqsheet CSV template using the attached timetable export. Do not change the CSV headers, column order, delimiters, or structure. Preserve the Reqsheet format exactly. Leave any uncertain value blank rather than guessing, and list every ambiguity separately after the CSV. Do not invent teachers, rooms, days, periods, or lesson spans.
```

## Historical and validation principles

Recurring timetable configuration and dated lesson/requisition records remain separate. A timetable change creates or selects an effective-dated version; it does not rewrite historical occurrences or requisitions. Multi-period lessons remain one underlying lesson and one eventual requisition even when displayed across several grid rows.

The existing database and service rules remain authoritative: ISO weekdays, valid ordered slots, contiguous teaching-only spans, effective-date boundaries, organisation ownership, teacher conflicts, and non-blank free-text class/room values must be validated before committing timetable data. Exceptions, holidays, cancellations, and other timetable overrides remain deferred.

## Not designed yet

Production-grade authentication and authorization hardening, timetable cloning, approval workflow, reporting, and detailed requisition workflow remain open design work. The current setup/login/session flow is deliberately pilot-grade. The first UI should be an intentionally skeletal, easy-to-change pilot implementation rather than final visual polish. Occurrence exceptions, holidays, cancellations, recurring-lesson edits after materialised history, and scheduled generation remain deferred.
