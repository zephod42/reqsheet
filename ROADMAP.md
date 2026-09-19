# Roadmap

This is an intentionally small, reviewable roadmap. The near-term priority is manual live testing of the actual website on Pumba with realistic schools and timetables. Avoid unnecessary architectural work until live testing exposes what needs changing; non-architectural visual/style decisions may continue in parallel.

Completed foundations:

- Admin configuration foundation: people, rooms, working days, week start, and ordered timetable structure.
- Timetable-version/admin editor UI: effective-dated versions, staff-member default view, and validated timetable editing by staff member, room, or day. The current editor is intentionally skeletal and is protected by the authenticated Admin permission.
- Documentation/code reconciliation: canonical product behaviour and the committed timetable editor have been checked for consistency in this pass.
- Teacher week-view skeleton and dated lesson planning editor: current configured week, navigable week controls, compact occurrence blocks, and direct editing of the three planning text fields. Completed in this milestone.
- Pilot first-run setup and login: initial organisation/admin creation, hashed passwords, sessions, operational-role landing, and explicit awaiting-first-login accounts. Completed in this milestone.
- Pilot-visible UI milestone: shared restrained visual foundation, public landing/login/signup, organisation settings and setup gating, teacher week class colours, and large lesson editor with explicit `Nothing required` action. Completed in this milestone.
- Pilot signup handoff: generic-host signup creates the organisation/Admin, then uses a one-time short-lived tenant-bound handoff to move the browser to the tenant host, establish a tenant-scoped session, and redirect to `/settings`.
- School tenant slug/host milestone: persistent domain-independent organisation slugs, configurable base-host resolution, tenant-aware login and protected-route consistency, unknown-tenant application 404s, cross-tenant session rejection, and school context on resolved pages. The final production domain is deliberately undecided/configurable; DuckDNS/Pumba is staging only and is not an application dependency.
- Versioned timetable-builder redesign: existing organisation users act as teachers, organisation rooms and reusable class resources are selected by assignments, and one version-tied assignment dataset is exposed through Teacher, Room, and Class projections with resource creation and per-version clash validation.
- Settings/timetable robustness: configurable period counts drive separators and seeded timetable structure; conjoined lessons may span any arbitrary contiguous teaching periods but never separators; fresh start time defaults to 08:00; and the authenticated shared sidebar remains consistent with admin destinations disabled for non-admins.
- Timetable data-flow and template-management milestone: Settings owns read-only active-template summaries, warned edits, and successor template creation; the assignment builder owns versioned teacher/room/class assignments; conjoined cards span their full visual extent; teacher initials and stable pastel class cards are shared across projections; effective assignments materialise into the authenticated teacher week with preserved planning/requisition records.

Agreed design decisions recorded on 2026-09-18:

- Public landing/auth direction: restrained `Reqsheet.` wordmark landing page, left navigation, email-free pilot sign-up creating an organisation and first Admin, remembered-login intent, and consistent incomplete-settings gating for Admin and non-Admin users.
- Settings direction: required school name, working days/week start, six default periods, and at least one explicitly added room; optional general/custom-day timings, typed separators, and disabled-by-default conjoined-period support that cannot cross separators.
- Visual and lesson-editing direction: restrained black-and-white Reqsheet chrome with technical timetable typography, neutral teacher grid with soft pastel lesson cards and consistent subtle class accents, neutral technician screen/black-and-white print, and a large lesson pop-out for exactly three free-text planning fields with an explicit `Nothing required` action. The pilot visual pass is implemented; further iteration remains expected.

Upcoming implementation sequence:

1. Manual live testing on Pumba: exercise signup/setup, tenant isolation, realistic resource-based timetable entry, teacher/room/class projections, teacher week/day editing, account/password workflows, technician workflows, and printing with representative schools.
2. Technician day-view skeleton and room preferences: current day, room columns, period rows, My rooms/All rooms/custom rooms, and teacher/room week inspection.
3. Technician print/PDF workflow: future-week selection, one A4 page per day, selected-room layouts, and practical page fitting.
4. Production deployment configuration when a domain is selected: configure DNS/web-server wildcard acceptance and production TLS coverage. The tenant architecture is already implemented and remains independent of the eventual domain.
5. Teacher lesson duplication: accessible copy workflows for visible and future lessons, overwrite confirmation, and practical undo.
6. Admin theme settings: exactly Primary and Secondary accent values, centrally applied without arbitrary CSS or coupling to class/grid/print colours.
7. Canonical timetable CSV import/export: Reqsheet template export, deterministic validation/preview, and confirmed import.
8. Authentication/authorization security hardening: replace pilot access and first-login handling with reviewed production mechanisms.
9. Pilot-driven UI iteration and polish: improve wording, layout, and workflow while preserving the simple server-rendered architecture.

Bounded usability milestone completed 2026-09-19:

- Teacher week now shows configured separator names once in the left period axis, with grey non-teaching cells and preserved conjoined-period behaviour.
- Shared navigation applies consistent selected-state styling, removes Sign up for authenticated users, preserves authenticated navigation on About/Demo/Contact, and adds My Account for every authenticated role.
- My Account renders only the current user’s identity and supports CSRF-protected self-service password changes under the existing password policy. Identity fields remain administrator-managed.
- Administrator Settings now contains the “Reqsheet account” placeholder section. Membership status, plans, pricing, renewals, payment management, and billing integration remain future work and must be specified before implementation.

People and navigation milestone completed 2026-09-19:

- Authenticated navigation now has a black general/application separator and labels the teacher destination “View My Timetable”.
- People is an organisation-scoped list with accessible Add/Edit dialogs, optional email, cumulative independent roles, and CSRF-protected validation.
- Migration `0008_add_people_roles_and_teacher_numbers.sql` adds optional email, independent operational-role flags, and immutable organisation-local teacher numbers. Existing users are backfilled by organisation and existing database IDs/relationships are preserved.

The database foundation and initial application-domain schema are complete and verified against local MySQL. Timetable configuration services provide the validated path for effective-dated versions, slots, and recurring lessons. The bounded occurrence-generation service validates timetable spans/conflicts and creates dated occurrences for explicit inclusive date ranges. Product behaviour and the remaining UI/admin scope are canonical in `PRODUCT_DESIGN.md`; authentication/authorization and exception handling remain future work.
