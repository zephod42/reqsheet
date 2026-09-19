# Security

Security is a design requirement from the beginning. The initial application-domain schema has staff identity records and pilot operational-role/admin fields, but no final authentication or authorization system. It has a lazy PDO connection, a generic database health response, a migration CLI, and a narrowly protected skeletal admin timetable editor.

Future implementation must at minimum address:

- password and session handling through reviewed, conventional mechanisms;
- the persistent/remembered-login product intent, including an explicit reviewed policy for lifetime, renewal, revocation, and protection against session theft;
- authorization checks on every protected action;
- server-side validation and safe output escaping;
- PDO prepared statements for database access;
- CSRF protection for state-changing forms;
- secure cookie and transport settings in deployment;
- least-privilege database credentials and safe secret management;
- separate runtime and migration database identities, with the runtime identity denied schema-changing privileges;
- migration review, backup procedures, and explicit handling of MySQL's non-atomic DDL before production application;
- audit logging appropriate to requisition and approval changes.
- continued tenant resolution and isolation for every school host, including strict validation of editable tenant slugs and uniqueness checks within Reqsheet’s database;
- host/base-domain configuration that avoids deriving tenant identity from an untrusted or hard-coded assumption;

Never commit credentials, `.env` files, logs, runtime data, or production configuration to this repository. The example environment files contain placeholders only; real runtime and migration secrets must be supplied from protected host configuration outside the repository. The health endpoint never returns connection details or exception messages.

The web runtime can load configuration only when `REQSHEET_ENV_FILE` explicitly names one absolute file outside the repository. The parser accepts literal `KEY=value` entries only, preserves already-defined process variables, and supports no shell execution or interpolation. Missing or malformed configured files fail as generic unhealthy application state; contents and credentials are not exposed.

School subdomains are implemented as a tenant-routing concern, not a registrar lookup. A persistent domain-independent `tenant_slug` is resolved only under the explicitly configured `REQSHEET_BASE_HOSTS` allowlist. `REQSHEET_CANONICAL_HOST` controls generated public signup/onboarding destinations; the same tenant resolves under every permitted base domain. Unknown tenants and unknown hosts return an application-level 404, and cross-organisation authenticated contexts are rejected. Signup uses a one-time short-lived tenant-bound handoff before establishing the normal tenant-scoped session on the tenant host. Session cookies are not shared across registrable domains. Tenant data and host routing remain portable when domains change. DNS, web-server acceptance, and TLS are deployment concerns.

Admin theming must remain limited to Primary and Secondary accent values. Arbitrary custom CSS is prohibited; theme accents must not be allowed to alter the black/white base, timetable grid, automatic class colours, or technician print output.

The pilot onboarding design temporarily permits a new Teacher or Technician account to exist in an explicit `awaiting-first-login` state without a password. At first login the user sets the password. This is a consciously accepted pilot weakness, not a production-ready authentication design; it requires a security review and a safer activation/reset flow before broader deployment. The state must never be represented by ambiguous blank-password handling.

The local development environment has been verified with separate `reqsheet_runtime` and `reqsheet_migrator` identities scoped to `reqsheet_dev`. The runtime identity has ordinary DML privileges only; the migration identity is reserved for schema changes. Protected environment files are maintained outside the repository under `/etc/reqsheet` and are not readable or writable by the application agent.

An administrator has verified that Apache/PHP-FPM receives only the external configuration-file path and that the protected runtime configuration produces a healthy `/health` response. No secret values are stored in or exposed by the application.

The schema does not include authentication or authorisation data. Cross-organisation relationships and timetable span rules require service-level validation in addition to the database foreign keys and checks. The timetable configuration services are the validated write path: they enforce organisation-scoped template names, explicit activation ownership, slot kind/time/order rules, organisation ownership, contiguous lesson spans, and teacher/room conflicts. Activation locks the organisation row and changes only its active-template reference. The current editor permits recurring-lesson edits/removals only before materialised history exists. The occurrence-generation service validates all requested lessons before its transactional DML phase and does not rewrite existing historical occurrences.

The optional MySQL integration harness is deliberately destructive within its test database: it accepts only the exact database name `reqsheet_test`, requires explicit `REQSHEET_TEST_DB_*` variables and `REQSHEET_RUN_INTEGRATION=1`, rejects the runtime/migration identities, refuses unexpected tables, uses synthetic fixtures only, and cleans the known schema tables afterward. It must never receive development or production credentials.

The public HTTPS route allowlist has been verified by an administrator. Unauthenticated public traffic is limited to the root, login, signup, informational landing links, and health routes; unknown and repository-looking paths return generic 404 responses, while Apache `FallbackResource /index.php` remains enabled. The admin timetable, settings, people, teacher, and technician routes are separately gated and are not part of the public route surface.

The admin timetable editor is now protected by an authenticated session with the Admin permission. Timetable mutations continue through the validated services; recurring lessons may be edited or removed only before materialised occurrences exist, and historical occurrences remain immutable. The prior environment-key admin gate remains only as test/development code and is not used by the normal browser route.

The skeletal teacher week view now requires a logged-in Teacher session; the temporary `REQSHEET_FIRST_DAY_OF_WEEK` environment setting only supplies week-shape scaffolding until organisation timetable settings own it. Teacher planning saves are scoped through the session’s organisation, teacher, and dated occurrence references and do not alter recurring timetable definitions.

Pilot browser login is implemented with PHP sessions, `password_hash`/`password_verify`, session-ID regeneration, HttpOnly/Lax cookies, and Secure cookies when HTTPS is detected. The agreed product direction includes a persistent/remembered-login experience, but its lifetime and security policy remain deferred to the authentication hardening pass. `/setup` requires the externally supplied `REQSHEET_SETUP_KEY` via temporary HTTP Basic authentication and is available only while no organisation exists. `/login`, `/logout`, teacher routes, technician placeholder, and admin pages use the authenticated session’s user, organisation, operational role, and Admin permission; browser-supplied organisation/user IDs are not trusted.

The authenticated My Account password-change form uses a session-bound CSRF token, verifies the current password, validates the existing minimum password policy and confirmation, hashes the replacement with `password_hash`, and scopes the update to the authenticated user and organisation. It does not expose password hashes or permit changes to administrator-managed identity fields. Password recovery, MFA, rate limiting, and advanced session revocation remain deferred.

Administrator People operations use session CSRF tokens and server-side organisation scoping for listing, creation, editing, role assignment, email storage, and teacher-number allocation. Role checks are cumulative while the administrator permission remains independent. The last active administrator cannot be removed through the edit workflow. Teacher numbers are display-only organisation-local values; immutable user IDs remain the relationship and authorisation identifiers and are never presented as teacher numbers.

Staff initials are validated server-side as exactly three A–Z letters, normalised to uppercase, and looked up only within the authenticated organisation. Existing organisation-scoped uniqueness constraints and service checks prevent duplicate login identifiers. Tenant login identity is rendered only from the trusted organisation returned by tenant resolution; generic, unknown, and cross-tenant hosts do not receive another organisation's identity or accounts.

The disposable test-data reset is CLI-only and uses the protected migration database identity. It refuses database names outside the explicit Reqsheet test allowlist, requires migration `0008`, derives tenant-owned deletion order from live foreign-key metadata, preserves `schema_migrations`, and requires an exact operator confirmation token. It does not expose an HTTP reset route or disable foreign-key enforcement.

The pilot deliberately defers password reset/recovery, email verification, MFA, brute-force/rate limiting, advanced session management, admin recovery, organisation ownership transfer, and broader abuse controls. The awaiting-first-login flow and temporary setup protection require review before wider public deployment.
