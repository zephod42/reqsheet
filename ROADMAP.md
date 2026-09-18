# Roadmap

This is an intentionally small, reviewable roadmap. The near-term priority is manual live testing of the actual website on Pumba with realistic schools and timetables. Avoid unnecessary architectural work until live testing exposes what needs changing; non-architectural visual/style decisions may continue in parallel.

Completed foundations:

- Admin configuration foundation: people, rooms, working days, week start, and ordered timetable structure.
- Timetable-version/admin editor UI: effective-dated versions, staff-member default view, and validated timetable editing by staff member, room, or day. Completed in `d2722f08c6cdc6af4bcd773a4b7ff30cbe5fed8a` (`Add timetable editor`). The current editor is intentionally skeletal and uses a temporary admin safeguard.
- Documentation/code reconciliation: canonical product behaviour and the committed timetable editor have been checked for consistency in this pass.
- Teacher week-view skeleton and dated lesson planning editor: current configured week, navigable week controls, compact occurrence blocks, and direct editing of the three planning text fields. Completed in this milestone.
- Pilot first-run setup and login: initial organisation/admin creation, hashed passwords, sessions, operational-role landing, and explicit awaiting-first-login accounts. Completed in this milestone.
- Pilot-visible UI milestone: shared restrained visual foundation, public landing/login/signup, organisation settings and setup gating, teacher week class colours, and large lesson editor with explicit `Nothing required` action. Completed in this milestone.
- School tenant slug/host milestone: domain-independent organisation slugs, configurable base-host resolution, tenant-aware login and protected-route consistency, and school context on resolved pages. Wildcard DNS/TLS and final production-domain selection remain deployment work.

Agreed design decisions recorded on 2026-09-18:

- Public landing/auth direction: restrained `Reqsheet.` wordmark landing page, left navigation, email-free pilot sign-up creating an organisation and first Admin, remembered-login intent, and consistent incomplete-settings gating for Admin and non-Admin users.
- Settings direction: required school name, working days/week start, six default periods, and at least one explicitly added room; optional general/custom-day timings, typed separators, and disabled-by-default double-period merging that cannot cross separators.
- Visual and lesson-editing direction: restrained black-and-white Reqsheet chrome with technical timetable typography, neutral teacher grid with white lesson cards and consistent subtle class accents, neutral technician screen/black-and-white print, and a large lesson pop-out for exactly three free-text planning fields with an explicit `Nothing required` action.

Upcoming implementation sequence:

1. Manual live testing on Pumba: exercise signup/setup, tenant isolation, realistic timetable entry, teacher week/day editing, technician workflows, and printing with representative schools.
2. Technician day-view skeleton and room preferences: current day, room columns, period rows, My rooms/All rooms/custom rooms, and teacher/room week inspection.
3. Technician print/PDF workflow: future-week selection, one A4 page per day, selected-room layouts, and practical page fitting.
4. Tenant deployment completion: select the eventual base domain, configure DNS/web-server wildcard acceptance, and provide production TLS coverage.
5. Teacher lesson duplication: accessible copy workflows for visible and future lessons, overwrite confirmation, and practical undo.
6. Admin theme settings: exactly Primary and Secondary accent values, centrally applied without arbitrary CSS or coupling to class/grid/print colours.
7. Canonical timetable CSV import/export: Reqsheet template export, deterministic validation/preview, and confirmed import.
8. Authentication/authorization security hardening: replace pilot access and first-login handling with reviewed production mechanisms.
9. Pilot-driven UI iteration and polish: improve wording, layout, and workflow while preserving the simple server-rendered architecture.

The database foundation and initial application-domain schema are complete and verified against local MySQL. Timetable configuration services provide the validated path for effective-dated versions, slots, and recurring lessons. The bounded occurrence-generation service validates timetable spans/conflicts and creates dated occurrences for explicit inclusive date ranges. Product behaviour and the remaining UI/admin scope are canonical in `PRODUCT_DESIGN.md`; authentication/authorization and exception handling remain future work.
