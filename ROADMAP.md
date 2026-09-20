# Roadmap

This is an intentionally small, reviewable roadmap. The near-term priority is manual live testing of the actual website on Pumba with realistic schools and timetables. Avoid unnecessary architectural work until live testing exposes what needs changing; non-architectural visual/style decisions may continue in parallel.

Completed foundations:

- Admin configuration foundation: people, rooms, working days, week start, and ordered timetable structure.
- Timetable-version/admin editor UI: named, manually activated templates, staff-member default view, and validated timetable editing by staff member, room, or day. The current editor is intentionally skeletal and is protected by the authenticated Admin permission.
- Documentation/code reconciliation: canonical product behaviour and the committed timetable editor have been checked for consistency in this pass.
- Teacher week-view skeleton and dated lesson planning editor: current configured week, navigable week controls, compact occurrence blocks, and direct editing of the three planning text fields. Completed in this milestone.
- Pilot first-run setup and login: initial organisation/admin creation, hashed passwords, sessions, operational-role landing, and explicit awaiting-first-login accounts. Completed in this milestone.
- Pilot-visible UI milestone: shared restrained visual foundation, public landing/login/signup, organisation settings and setup gating, teacher week class colours, and large lesson editor with explicit `Nothing required` action. Completed in this milestone.
- Pilot signup handoff: generic-host signup creates the organisation/Admin, then uses a one-time short-lived tenant-bound handoff to move the browser to the tenant host, establish a tenant-scoped session, and redirect to `/settings`.
- School tenant slug/host milestone: persistent domain-independent organisation slugs, configurable base-host resolution, tenant-aware login and protected-route consistency, unknown-tenant application 404s, cross-tenant session rejection, and school context on resolved pages. The final production domain is deliberately undecided/configurable; DuckDNS/Pumba is staging only and is not an application dependency.
- Versioned timetable-builder redesign: existing organisation users act as teachers, organisation rooms and reusable class resources are selected by assignments, and one version-tied assignment dataset is exposed through Teacher, Room, and Class projections with resource creation and per-version clash validation.
- Settings/timetable robustness: configurable period counts drive separators and seeded timetable structure; conjoined lessons may span any arbitrary contiguous teaching periods but never separators; fresh start time defaults to 08:00; and the authenticated shared sidebar remains consistent with admin destinations disabled for non-admins.
- Timetable data-flow and template-management milestone: Settings owns named-template creation, explicit activation, read-only active-template summaries, and warned edits; the assignment builder owns versioned teacher/room/class assignments; conjoined cards span their full visual extent; teacher initials and stable pastel class cards are shared across projections; active assignments materialise into the authenticated teacher week with preserved planning/requisition records.

Agreed design decisions recorded on 2026-09-18:

- Public landing/auth direction: restrained `Reqsheet.` wordmark landing page, left navigation, email-free pilot sign-up creating an organisation and first Admin, remembered-login intent, and consistent incomplete-settings gating for Admin and non-Admin users.
- Settings direction: required school name, working days/week start, and six default periods; rooms are created in the timetable builder, while optional general/custom-day timings, typed separators, and disabled-by-default conjoined-period support remain configurable later without blocking initial setup.
- Visual and lesson-editing direction: restrained black-and-white Reqsheet chrome with technical timetable typography, neutral teacher grid with soft pastel lesson cards and consistent subtle class accents, neutral technician screen/black-and-white print, and a large lesson pop-out for exactly three free-text planning fields with an explicit `Nothing required` action. The pilot visual pass is implemented; further iteration remains expected.

Staff account and pilot workflow refinement completed 2026-09-19:

- Known tenant roots route to school login while recognised base roots retain the public landing page.
- People creates email-free staff accounts with explicit awaiting-first-login state; staff choose a password after initials-plus-blank-password entry, and administrators can reset ordinary staff accounts with tenant-scoped CSRF protection and session revocation.
- Organisation signup and staff account management collect no email addresses.
- Technician day/week labels and selected-week browser printing use configured working days, while room display defaults to all rooms and no longer exposes personal room saving.

The pilot first-login workflow retains an account-claiming risk: anyone who knows a newly created staff member’s initials may claim the account before the intended person. It is not identity verification and remains unsuitable as a final public authentication design.

Technician and timetable presentation refinement completed 2026-09-19:

- Technician requisition cells now retain full accessible text, emphasise teacher initials, distinguish free rooms, and print complete requisitions.
- Selected-week printing renders configured working days from the selected date and invokes the native browser print dialog automatically.
- Teacher and technician planning resolve effective timetable versions by lesson date, preserving historical occurrence slot relationships across version boundaries.
- Teacher multi-period cards fill their row-spanned planner cells, and all resource-builder lesson modes return to the builder after successful creation.
- Base-domain requests remain public splash pages even when a tenant session cookie is present; tenant hosts continue to route to tenant login.

Canonical timetable CSV milestone 1 completed 2026-09-19:

- An organisation administrator can export the selected template as the exact five-column `Day,Period,Room,Class,Teacher` CSV.
- Export includes only ordered teaching slots and current organisation rooms; Class and Teacher remain blank, separators are omitted, and existing assignments or historical planning data are never queried.
- The later import is documented as deterministic upload/validation, no-write preview, and a separately confirmed atomic insert into the existing recurring-lesson model. Empty-template revalidation and manual activation remain mandatory boundaries.

Canonical timetable CSV milestone 2 completed 2026-09-19:

- The timetable builder accepts the exact Reqsheet CSV through an admin-only, CSRF-protected, 2 MiB/20,000-row bounded upload and produces an escaped read-only preview without database writes.
- Validation regenerates the complete version/room structure, rejects missing, duplicate, extra and cross-tenant references, resolves only existing eligible teachers/classes/rooms, and checks teacher/class/room occupancy conflicts.
- Consecutive identical entries become separator-aware multi-period proposals under the organisation's conjoined-period setting; previews are held as user/tenant-bound 15-minute session drafts and remain non-authoritative.
- A separate admin resource-reference CSV lists eligible teacher codes/names, class codes and room codes without staff emails or account/security data.

Canonical timetable CSV milestone 3 completed 2026-09-19:

- Validated previews now have explicit Import Timetable and Cancel actions bound to the current administrator, tenant, CSRF token and unexpired one-time session draft.
- Confirmation locks and rechecks the empty organisation-owned version, current structure, rooms, classes, eligible teachers and conjoined-period setting in one database transaction before rerunning deterministic validation.
- Every recurring assignment is inserted atomically or the complete operation rolls back; the version lock serialises competing imports, while completed/cancelled drafts cannot be reused.
- Import leaves manual activation, dated occurrences and requisitions unchanged and returns successful administrators to the populated timetable builder.

Canonical timetable CSV revised destination workflow completed 2026-09-20:

- A confirmed CSV import clones the selected source timetable's complete structure into a new automatically named timetable, creates missing classes atomically, inserts retained assignments, and activates the new timetable immediately.
- Populated source timetables remain unchanged; previous templates remain available for normal reactivation and historical occurrences/requisitions are not rewritten.
- Unknown rooms are skipped with preview/success warnings, unknown classes are created during confirmation, and unknown or ineligible teachers in recognized rooms block the transaction.

Upcoming implementation sequence:

1. Manual live testing on Pumba: exercise signup/setup, tenant isolation, realistic resource-based timetable entry, teacher/room/class projections, teacher week/day editing, account/password workflows, technician workflows, and printing with representative schools.
2. Technician day-view and room preferences completed 2026-09-19: current/future day navigation, room columns and separator rows, persisted requisitions, My rooms/All rooms/custom rooms, personal defaults, and secondary teacher/room inspection views.
3. Technician browser-print workflow completed 2026-09-19: selected-room daily preparation sheet, A4 landscape print CSS, one selected day per print page, neutral black-and-white output, and native browser printing.
4. Production deployment configuration: application support for `reqsheet.com` plus retained DuckDNS domains is implemented; DNS/web-server wildcard acceptance and production TLS coverage remain administrator deployment work.
5. Teacher lesson duplication: accessible copy workflows for visible and future lessons, overwrite confirmation, and practical undo.
6. Admin theme settings: exactly Primary and Secondary accent values, centrally applied without arbitrary CSS or coupling to class/grid/print colours.
7. Authentication/authorization security hardening: replace pilot access and first-login handling with reviewed production mechanisms.
8. Pilot-driven UI iteration and polish: improve wording, layout, and workflow while preserving the simple server-rendered architecture.

Bounded usability milestone completed 2026-09-19:

- Teacher week now shows configured separator names once in the left period axis, with grey non-teaching cells and preserved conjoined-period behaviour.
- Shared navigation applies consistent selected-state styling, removes Sign up for authenticated users, preserves authenticated navigation on About/Demo/Contact, and adds My Account for every authenticated role.
- My Account renders only the current user’s identity and supports CSRF-protected self-service password changes under the existing password policy. Identity fields remain administrator-managed.
- Administrator Settings now contains the “Reqsheet account” placeholder section. Membership status, plans, pricing, renewals, payment management, and billing integration remain future work and must be specified before implementation.

Teacher day view and timetable-settings refinement completed 2026-09-19:

- The timetable settings editor now exposes the standard working-day, week-start, period, timing and separator controls without the legacy per-day custom timing UI; saved legacy timing data remains preserved by the backend.
- The active timetable summary uses compact labelled rows for the saved working days, week start, periods, timings, separators and conjoined-period setting.
- Teachers can open a tenant-scoped Day View from navigation or any Teacher Week View day heading. It retrieves the selected date through the existing effective-version/dated-occurrence path, shows complete planning text and multi-period ranges once, and links editing to the established teacher lesson editor.

People and navigation milestone completed 2026-09-19:

- Authenticated navigation now has a black general/application separator and labels the teacher destination “View My Timetable”.
- People is an organisation-scoped list with accessible Add/Edit dialogs, cumulative independent roles, and CSRF-protected validation.
- Migration `0008_add_people_roles_and_teacher_numbers.sql` originally added optional email alongside independent operational-role flags and immutable organisation-local teacher numbers; migration `0012` removes that email column while preserving existing users, roles, identifiers, and teacher numbers.

Session robustness refinement completed 2026-09-19:

- Session payloads are versioned and account state/revocation is revalidated at request time; stale, revoked, cross-tenant, and incompatible sessions are cleared without role redirects.
- Dynamic tenant/session responses use private no-store headers, while the shared stylesheet uses a file-version query parameter so normal static caching does not retain deployed CSS.
- Schema/database failures remain controlled service errors and are not disguised as authentication failures. Migration 0012 must be applied before serving the current account/recovery code on an older database.

The database foundation and initial application-domain schema are complete and verified against local MySQL. Timetable configuration services provide the validated path for effective-dated versions, slots, and recurring lessons. The bounded occurrence-generation service validates timetable spans/conflicts and creates dated occurrences for explicit inclusive date ranges. Product behaviour and the remaining UI/admin scope are canonical in `PRODUCT_DESIGN.md`; authentication/authorization and exception handling remain future work.

Bounded login refinement completed 2026-09-19:

- Tenant login shows only the trusted registered school name and short code; the generic host remains school-neutral.
- Staff initials are now exactly three A–Z letters, normalised to uppercase and unique per organisation. They are the organisation-scoped login identifier and remain separate from internal IDs and teacher numbers.
- Billing, payment processing, MFA, and persistent-login policy remain deferred. Organisation-key administrator recovery is implemented.

Account recovery and data-minimisation milestone completed 2026-09-20:

- Reqsheet account and organisation records are email-free. Migration `0012` removes active stored staff email and organisation contact-email values without deleting accounts or tenant data; historical backups age out only under normal retention policy.
- New schools receive a one-time 256-bit Organisation Recovery Key after authenticated tenant handoff. Existing schools establish one through an administrator-only, password-confirmed flow. Only a domain-separated SHA-256 digest is persistent.
- Tenant login exposes Account Recovery for existing administrators only. Verification creates a short-lived single-use flow; password completion revokes that administrator's sessions and atomically rotates the organisation key. Unrestricted access remains blocked until the replacement key is acknowledged, and interrupted presentations can be safely replaced.
- Administrators can replace a lost or compromised recovery key from Settings using CSRF protection, explicit confirmation, and current-password reauthentication. There is no operator bypass or universal key.
- New school short codes use 3–12 lowercase letters/digits, with `www` reserved case-insensitively; historical slugs remain routable unchanged.
- My Account preserves all current roles and displays organisation-wide trial/paid countdown metadata when explicitly configured, otherwise `Not configured`. No trial, paid state, billing address, or renewal is invented.

Test-data reset handoff completed 2026-09-19:

- `bin/reset-test-data.php` provides an explicit dry-run and confirmed CLI reset for the disposable Reqsheet test databases. It preserves schema and migration history; fresh school creation remains an administrator verification step after execution.

Timetable template creation refinement completed 2026-09-19:

- New templates are created with minimal version metadata and authenticated organisation context. Existing settings and timetable editor controls remain available after creation; no school-name or detailed timing/configuration fields are required in the initial template form.
