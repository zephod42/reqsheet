# Security

Security is a design requirement from the beginning. The initial application-domain schema has staff identity records but no authentication, sessions, roles, or application behaviour. It has a lazy PDO connection, a generic database health response, and a migration CLI.

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

The local development environment has been verified with separate `reqsheet_runtime` and `reqsheet_migrator` identities scoped to `reqsheet_dev`. The runtime identity has ordinary DML privileges only; the migration identity is reserved for schema changes. Protected environment files are maintained outside the repository under `/etc/reqsheet` and are not readable or writable by the application agent.

An administrator has verified that Apache/PHP-FPM receives only the external configuration-file path and that the protected runtime configuration produces a healthy `/health` response. No secret values are stored in or exposed by the application.

The schema does not include authentication or authorisation data. Cross-organisation relationships and timetable span rules require service-level validation in addition to the database foreign keys and checks. The timetable configuration services are the create-only validated write path: they enforce effective-date non-overlap, slot kind/time/order rules, organisation ownership, contiguous lesson spans, and teacher/room conflicts. The occurrence-generation service validates all requested lessons before its transactional DML phase and does not rewrite existing historical occurrences. No timetable update service exists until immutability rules for materialised history are designed.

The optional MySQL integration harness is deliberately destructive within its test database: it accepts only the exact database name `reqsheet_test`, requires explicit `REQSHEET_TEST_DB_*` variables and `REQSHEET_RUN_INTEGRATION=1`, rejects the runtime/migration identities, refuses unexpected tables, uses synthetic fixtures only, and cleans the known schema tables afterward. It must never receive development or production credentials.
