# Roadmap

This is an intentionally small, reviewable roadmap. The next implementation milestone is the teacher week-view skeleton for real pilot iteration, not final visual polish.

Completed foundations:

- Admin configuration foundation: people, rooms, working days, week start, and ordered timetable structure.
- Timetable-version/admin editor UI: effective-dated versions, staff-member default view, and validated timetable editing by staff member, room, or day. Completed in `d2722f08c6cdc6af4bcd773a4b7ff30cbe5fed8a` (`Add timetable editor`). The current editor is intentionally skeletal and uses a temporary admin safeguard.
- Documentation/code reconciliation: canonical product behaviour and the committed timetable editor have been checked for consistency in this pass.
- Teacher week-view skeleton and dated lesson planning editor: current configured week, navigable week controls, compact occurrence blocks, and direct editing of the three planning text fields. Completed in this milestone.
- Pilot first-run setup and login: initial organisation/admin creation, hashed passwords, sessions, operational-role landing, and explicit awaiting-first-login accounts. Completed in this milestone.
- Pilot-visible UI milestone: shared restrained visual foundation, public landing/login/signup, organisation settings and setup gating, teacher week class colours, and large lesson editor with explicit `Nothing required` action. Completed in this milestone.

Agreed design decisions recorded on 2026-09-18:

- Public landing/auth direction: restrained `Reqsheet.` wordmark landing page, left navigation, email-free pilot sign-up creating an organisation and first Admin, remembered-login intent, and consistent incomplete-settings gating for Admin and non-Admin users.
- Settings direction: required school name, working days/week start, six default periods, and at least one explicitly added room; optional general/custom-day timings, typed separators, and disabled-by-default double-period merging that cannot cross separators.
- Visual and lesson-editing direction: black-and-white Reqsheet chrome with technical timetable typography, teacher-only consistent pastel class colours, neutral technician screen/black-and-white print, and a large lesson pop-out for the three optional planning fields with an explicit `Nothing required` action.

Upcoming implementation sequence:

1. Technician day-view skeleton and room preferences: current day, room columns, period rows, My rooms/All rooms/custom rooms, and teacher/room week inspection.
2. Technician print/PDF workflow: future-week selection, one A4 page per day, selected-room layouts, and practical page fitting.
3. Canonical timetable CSV import/export: Reqsheet template export, deterministic validation/preview, and confirmed import.
4. Authentication/authorization security hardening: replace pilot access and first-login handling with reviewed production mechanisms.
5. Pilot-driven UI iteration and polish: improve wording, layout, and workflow while preserving the simple server-rendered architecture.

The database foundation and initial application-domain schema are complete and verified against local MySQL. Timetable configuration services provide the validated path for effective-dated versions, slots, and recurring lessons. The bounded occurrence-generation service validates timetable spans/conflicts and creates dated occurrences for explicit inclusive date ranges. Product behaviour and the remaining UI/admin scope are canonical in `PRODUCT_DESIGN.md`; authentication/authorization and exception handling remain future work.
