# Local setup

## Requirements

- PHP 8.2 or newer
- Composer 2.x
- MySQL 8.4 client/server access

The repository includes a small PDO/database foundation, migration CLI, and initial application-domain schema. It does not create MySQL users or databases and does not require privileged host changes.

## Bootstrap

From the repository root:

```sh
composer dump-autoload
composer test
```

If Packagist is reachable, install the declared development tools:

```sh
composer install
composer test:phpunit
```

## Database foundation

`.env.example` and `.env.migrate.example` are templates only. The application does not load these files automatically. Supply the real values through protected host configuration or the process environment; never commit real `.env` or `.env.migrate` files.

Runtime configuration uses `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`. Migration configuration uses the corresponding `MIGRATION_DB_*` variables and must use a separate migration identity. With those migration variables exported:

```sh
php bin/migrate.php
```

Tenant host resolution uses `REQSHEET_BASE_HOSTS`, a comma-separated allowlist of public root/base hostnames, plus `REQSHEET_CANONICAL_HOST` for the preferred public address. For the purchased domain and retained staging domain use `REQSHEET_BASE_HOSTS=reqsheet.com,reqsheet.duckdns.org` and `REQSHEET_CANONICAL_HOST=reqsheet.com`. Values must not include a scheme, port, tenant slug, or path. `REQSHEET_BASE_HOST` remains supported for a single-domain/local deployment. Tenant resolution is disabled when no value is present, except for localhost/loopback development; deployments must set it explicitly rather than deriving it from the request Host header or a non-local web-server name. Tenant slugs remain domain-independent, so adding a permitted domain does not require a schema or data migration.

For the web runtime, the administrator may set `REQSHEET_ENV_FILE` to one explicit absolute path outside the repository. The application parses only literal `KEY=value` lines, ignores blank lines and comments, does not execute shell syntax or interpolation, and does not overwrite variables already supplied to the process. If the variable is absent, the existing process-environment behaviour is unchanged. For the protected local runtime configuration, the Apache vhost should set only the path:

```apache
SetEnv REQSHEET_ENV_FILE /etc/reqsheet/reqsheet-runtime.env
```

The application does not search for `.env` files and does not load repository environment files. A configured file that is missing, malformed, relative, or inside the repository causes database-backed requests to fail safely with the existing generic unhealthy response; file contents are never returned.

The repository currently contains migrations `0001` through `0011`. The command applies SQL files from `database/migrations/` in numeric version order and records applied versions in `schema_migrations`. It is safe to rerun after a successful migration; already-recorded versions are skipped. Migration versions and names must be unique. Restricted Codex work may author and test migration files, but applying them to a protected database is an administrator operation using the protected migration identity. Repository inspection alone cannot prove which migrations are applied to a live database; that is an intentional security boundary, not an implementation gap. The domain migrations create organisations, users, organisation settings and rooms, timetable versions and slots, recurring lessons, dated lesson occurrences, requisitions, organisation contact email, and account session-revocation state. They do not seed data or generate occurrences.

The administrator-only `bin/reset-test-data.php` command is the supported disposable-test-data reset. It uses the protected `MIGRATION_DB_*` configuration, accepts only the documented `reqsheet_dev` or `reqsheet_test` database names, discovers tenant-owned tables through foreign keys, preserves `schema_migrations`, and deletes child records before organisations. It is never run automatically. Review the dry run first:

```sh
php bin/reset-test-data.php --dry-run
php bin/reset-test-data.php --confirm=DELETE-ALL-REQSHEET-TEST-DATA
```

The destructive command requires the exact confirmation token and runs inside a transaction. It must be used only for the explicitly disposable Reqsheet test database; it does not create a backup, drop tables, disable foreign-key checks, or reset schema.

MySQL DDL can implicitly commit and is not fully transactional. A failed migration is not recorded as applied, but a migration that fails after some DDL may leave partial schema changes. Review and repair the database before rerunning such a migration; migrations should be small, forward-only, and safe to retry where practical.

The migration identity should have schema-changing privileges scoped only to the Reqsheet database, including `CREATE`, `ALTER`, and `DROP` as future migrations may need to replace or remove objects. The runtime identity should have only application DML privileges and no schema-changing privileges.

The database enforces keys, foreign keys, required values, date/time ranges, allowed slot kinds, allowed requisition states, and simple organisation-local uniqueness. Service validation must enforce non-overlapping timetable-version date ranges, same-version/day slot relationships, contiguous teaching-only lesson spans, occurrence dates matching recurring lessons, cross-organisation consistency, requisition-state/content consistency, and room/teacher conflict detection. Room conflict comparison trims surrounding whitespace and compares case-insensitively; the stored room code is unchanged.

## Timetable configuration services

The current resource-based editor supersedes any older description of class or room codes as free-text assignment fields: assignments select organisation-scoped teacher, room, and class resources.

Any remaining reference below to free-text class/room codes describes stored historical/snapshot values, not the current admin assignment editor.

The configuration services are the intended application path for writing named timetable templates, slots, and recurring lessons. Template names are unique within an organisation, activation is explicit and organisation-scoped, and `TimetableSlotService` validates ISO weekdays, positive sequence/period values, allowed kinds, valid non-overlapping times, and coherent sequence/chronological order. `RecurringLessonService` validates organisation ownership, start-slot relationships, contiguous teaching-only spans, non-blank free-text class/room codes, and teacher/room conflicts. The admin editor uses these services and allows recurring-lesson edits/removals only before materialised occurrences exist. These services use `TimetableValidationException` for expected invalid input; PDO/database failures remain operational exceptions.

Timetable template management is in Settings: the active template is summarized read-only, editing requires a warning, and administrators explicitly activate one named template for the organisation. Protected historical versions and occurrences are not mutated. Recurring lessons can be edited or removed through the admin editor only before materialised historical occurrences exist.

## Bounded occurrence generation

Occurrence generation uses the runtime database environment and requires explicit IDs and dates. The end date is inclusive; timetable version `effective_to` remains exclusive. For example:

```sh
php bin/generate-occurrences.php \
  --organisation=1 \
  --version=1 \
  --start=2026-09-01 \
  --end=2026-09-30
```

The service clips generation to the timetable version’s effective range, generates only matching recurring weekdays, validates contiguous teaching-only spans and teacher/room conflicts, and reports generated versus already-existing occurrences. Existing occurrences are never rewritten. All validation runs before insertion; occurrence inserts are then performed in one DML transaction and rolled back on failure.

Occurrence generation is an explicit CLI operation. No cron, scheduler, holiday handling, cancellation, or timetable-exception behaviour exists yet.

## Optional MySQL integration test

The integration test is separate from `composer test` and is disabled unless explicitly opted in. It accepts only the dedicated `REQSHEET_TEST_DB_*` environment variables and refuses any database name other than exactly `reqsheet_test`. It never reads `DB_*` or `MIGRATION_DB_*`, and it rejects the runtime and migration usernames.

The test database must be disposable and dedicated to this harness. It refuses unexpected pre-existing tables, applies the migrations, inserts synthetic fixtures, exercises the real PDO store and generator, then removes the known test tables in cleanup. It must never be pointed at `reqsheet_dev` or production.

With protected test variables exported:

```sh
REQSHEET_RUN_INTEGRATION=1 composer test:integration
```

## Verified development setup

The local development setup has been provisioned and exercised by an administrator:

- database: `reqsheet_dev`;
- runtime identity: `reqsheet_runtime`, limited to `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on that database;
- migration identity: `reqsheet_migrator`, with privileges scoped to that database;
- protected environment sources: `/etc/reqsheet/reqsheet-runtime.env` and `/etc/reqsheet/reqsheet-migrate.env`;
- first migration: `Applied 1 migration.`;
- repeat migration: `Applied 0 migrations.`;
- These are historical administrator-run setup checks; they do not establish the currently applied migration set. The protected database remains the source of truth for that state.
- `GET /health`: HTTP 200 with `{"status":"ok"}`;
- `POST /health`: HTTP 405.
- Apache/PHP-FPM runtime loading: verified through the Reqsheet vhost with the protected external configuration path;
- vhost smoke checks: `/health` returns HTTP 200 with `{"status":"ok"}`, `/` returns HTTP 200 with `Reqsheet ok`, and `POST /health` returns HTTP 405.

The protected environment files are administrator-managed and are intentionally unreadable by the application agent. Their secret values are not stored in this repository. Production must use separately named database identities, database names, and credentials.

The runtime application connects lazily. Ordinary requests do not require MySQL; `GET /health` does, and returns HTTP 503 with a generic response when configuration or the connection is unavailable. Non-GET `/health` requests return HTTP 405.

For a local smoke test of the public entry point:

```sh
php -S 127.0.0.1:8080 -t public
```

Stop the development server with `Ctrl-C`. Production deployment and Apache configuration are intentionally outside this bootstrap.

The public HTTPS routing has been verified by an administrator: `GET /`, `GET /login`, and `GET /health` succeed, `POST /health` returns 405, and unknown or repository-looking paths return 404. The public pilot also exposes `/signup`, `/about`, `/demo`, and `/contact`; protected application routes remain session-gated. Apache continues to use `FallbackResource /index.php`; the application allowlist prevents that fallback from exposing non-public repository paths.

The skeletal admin timetable editor requires a logged-in account with Admin permission and uses the session organisation; it no longer relies on an environment-selected user or organisation. It supports staff, room, and day inspection plus validated recurring-lesson creation/editing/removal. Lessons with historical occurrences are immutable.

For a selected template, **Export Blank CSV** downloads the canonical `Day,Period,Room,Class,Teacher` structure from `/admin/timetable/export.csv`. The export contains current organisation rooms and teaching slots only; Class and Teacher are blank and existing lessons are deliberately ignored. At least one room and one teaching period are required. CSV import/preview/commit is documented in `TIMETABLE_CSV.md` but is not implemented in this milestone.

The skeletal teacher week view requires a logged-in Teacher account. Open `/teacher` (or `/teacher/week`) through the local server or protected vhost to review the current configured week. On load, the view uses the effective timetable version and materialises missing dated occurrences for that selected week through the existing occurrence generator; planning/requisition data remains stored against those dated snapshots. `REQSHEET_FIRST_DAY_OF_WEEK` may be supplied as an ISO weekday number for temporary development review and defaults to `1` (Monday); it remains scaffolding until organisation timetable settings own it.

## Pilot first-run setup and login

After applying all migrations to a new database, add a strong secret as `REQSHEET_SETUP_KEY` in the protected runtime environment file outside the repository, such as `/etc/reqsheet/reqsheet-runtime.env`. Do not put the value in this repository. While the database has no organisations, open `/setup` and use HTTP Basic authentication with username `setup` and that secret to create the first organisation and its Teacher or Technician administrator. The setup route becomes unavailable after the first organisation is created.

The first-run browser URL is `/setup`. After setup, use `/login` with the first user’s three-letter initials and password. Teachers are sent to `/teacher`; technicians are sent to `/technician`, which is currently a safe placeholder. Admin is an additional permission and does not change either operational landing page. Authenticated admins can create further users at `/admin/people`; those accounts are explicitly awaiting first login and set their password from `/login`.

Pilot sessions use PHP sessions with regenerated IDs, HttpOnly/Lax cookies, Secure cookies when HTTPS is detected, and a versioned payload. Incompatible or revoked sessions are cleared and return to the tenant login; a database/schema failure returns a controlled 503 instead of being treated as a bad login. Password changes and administrator resets revoke the account's existing sessions. Dynamic responses are non-storable, while CSS assets are versioned from their file modification time. When deploying a schema-dependent application release, the administrator must apply migrations before serving the new code: `cd /var/www/reqsheet`, `set -a`, `. /etc/reqsheet/reqsheet-migrate.env`, `set +a`, `php bin/migrate.php`, then deploy/restart the application as established. Migration 0011 is required by the current account/session code and has not been verified as applied on Pumba. A schema-dependent deployment may clear incompatible old sessions, but ordinary CSS/UI changes do not invalidate every user. The agreed product direction is for ordinary users to have a persistent/remembered login during normal daily use; the exact lifetime and related policy remain for the authentication hardening pass.

The public pilot signup route is `/signup`; on the generic base host it creates the first organisation and Admin account without email, issues a one-time short-lived tenant-bound onboarding handoff, and moves the browser to the tenant hostname. The tenant consumes the handoff, establishes the normal tenant-scoped session, and redirects that Admin to `/settings`. Required settings are school name, working days, first working day, and periods per day. Rooms are created through the timetable builder, and optional timings can be configured later from Settings. An Admin with incomplete settings is sent directly to `/settings`. A non-Admin sees the blocking message `Something's missing...` followed by `Settings need to be configured. Contact your admin.` rather than an empty operational screen. The legacy protected `/setup` bootstrap remains available only while no organisation exists.

## Tenant host configuration

School URLs use `<tenant_slug>.<allowed-base-domain>`. Both configured root domains remain generic public entry and sign-up routes, while `REQSHEET_CANONICAL_HOST` controls new signup/setup examples and onboarding destinations. The persistent tenant slug remains domain-independent. The shared application resolves either allowed domain to the same isolated organisation; unknown tenants and unknown hosts return an application-level 404, and an authenticated context for another organisation is rejected. Signup/setup stores a validated, Admin-editable tenant slug and checks uniqueness in Reqsheet’s database; it does not perform registrar availability checks.

For Pumba/staging, equivalent subdomains under the current DuckDNS base may be used to exercise real tenant routing. This is staging detail only. Changing the configured base domain should require DNS/TLS/base-domain configuration changes only; the resolved school landing/login page should show the school name near or below the Reqsheet branding.
