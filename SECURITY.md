# Security

Security is a design requirement from the beginning. The initial application-domain schema has staff identity records but no authentication, sessions, or application roles. It has a lazy PDO connection, a generic database health response, a migration CLI, and a narrowly protected skeletal admin timetable editor.

Future implementation must at minimum address:

- password and session handling through reviewed, conventional mechanisms;
- authorization checks on every protected action;
- server-side validation and safe output escaping;
- PDO prepared statements for database access;
- CSRF protection for state-changing forms;
- secure cookie and transport settings in deployment;
- least-privilege database credentials and safe secret management;
- separate runtime and migration database identities, with the runtime identity denied schema-changing privileges;
- migration review, backup procedures, and explicit handling of MySQL's non-atomic DDL before production application;
- audit logging appropriate to requisition and approval changes.

Never commit credentials, `.env` files, logs, runtime data, or production configuration to this repository. The example environment files contain placeholders only; real runtime and migration secrets must be supplied from protected host configuration outside the repository. The health endpoint never returns connection details or exception messages.

The web runtime can load configuration only when `REQSHEET_ENV_FILE` explicitly names one absolute file outside the repository. The parser accepts literal `KEY=value` entries only, preserves already-defined process variables, and supports no shell execution or interpolation. Missing or malformed configured files fail as generic unhealthy application state; contents and credentials are not exposed.

The pilot onboarding design temporarily permits a new Teacher or Technician account to exist in an explicit `awaiting-first-login` state without a password. At first login the user sets the password. This is a consciously accepted pilot weakness, not a production-ready authentication design; it requires a security review and a safer activation/reset flow before broader deployment. The state must never be represented by ambiguous blank-password handling.

The local development environment has been verified with separate `reqsheet_runtime` and `reqsheet_migrator` identities scoped to `reqsheet_dev`. The runtime identity has ordinary DML privileges only; the migration identity is reserved for schema changes. Protected environment files are maintained outside the repository under `/etc/reqsheet` and are not readable or writable by the application agent.

An administrator has verified that Apache/PHP-FPM receives only the external configuration-file path and that the protected runtime configuration produces a healthy `/health` response. No secret values are stored in or exposed by the application.

The schema does not include authentication or authorisation data. Cross-organisation relationships and timetable span rules require service-level validation in addition to the database foreign keys and checks. The timetable configuration services are the validated write path: they enforce effective-date non-overlap, slot kind/time/order rules, organisation ownership, contiguous lesson spans, and teacher/room conflicts. The current editor permits recurring-lesson edits/removals only before materialised history exists. The occurrence-generation service validates all requested lessons before its transactional DML phase and does not rewrite existing historical occurrences.

The optional MySQL integration harness is deliberately destructive within its test database: it accepts only the exact database name `reqsheet_test`, requires explicit `REQSHEET_TEST_DB_*` variables and `REQSHEET_RUN_INTEGRATION=1`, rejects the runtime/migration identities, refuses unexpected tables, uses synthetic fixtures only, and cleans the known schema tables afterward. It must never receive development or production credentials.

The public HTTPS route allowlist has been verified by an administrator. Unauthenticated public traffic is limited to the root and health routes; unknown and repository-looking paths return generic 404 responses, while Apache `FallbackResource /index.php` remains enabled. The admin timetable route is separately gated and is not part of the public route surface.

The admin timetable editor is not public by default: it requires an externally supplied organisation ID and temporary admin key, and rejects requests without HTTP Basic credentials matching that key. This narrow safeguard is not a replacement for the deferred authentication/authorization system. Timetable mutations continue through the validated services; recurring lessons may be edited or removed only before materialised occurrences exist, and historical occurrences remain immutable.

The skeletal teacher week view is likewise disabled unless protected configuration supplies a teacher ID, organisation ID, and temporary teacher key. It uses HTTP Basic credentials only as a development access mechanism; this is not the deferred authentication system. Teacher planning saves are scoped through the organisation, teacher, and dated occurrence references and do not alter recurring timetable definitions.
