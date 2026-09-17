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

The local development environment has been verified with separate `reqsheet_runtime` and `reqsheet_migrator` identities scoped to `reqsheet_dev`. The runtime identity has ordinary DML privileges only; the migration identity is reserved for schema changes. Protected environment files are maintained outside the repository under `/etc/reqsheet` and are not readable or writable by the application agent.

The schema does not include authentication or authorisation data. Cross-organisation relationships and timetable span rules require service-level validation in addition to the database foreign keys and checks.
