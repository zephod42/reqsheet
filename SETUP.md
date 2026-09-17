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

For the web runtime, the administrator may set `REQSHEET_ENV_FILE` to one explicit absolute path outside the repository. The application parses only literal `KEY=value` lines, ignores blank lines and comments, does not execute shell syntax or interpolation, and does not overwrite variables already supplied to the process. If the variable is absent, the existing process-environment behaviour is unchanged. For the protected local runtime configuration, the Apache vhost should set only the path:

```apache
SetEnv REQSHEET_ENV_FILE /etc/reqsheet/reqsheet-runtime.env
```

The application does not search for `.env` files and does not load repository environment files. A configured file that is missing, malformed, relative, or inside the repository causes database-backed requests to fail safely with the existing generic unhealthy response; file contents are never returned.

The command applies SQL files from `database/migrations/` in numeric version order and records applied versions in `schema_migrations`. It is safe to rerun after a successful migration; already-recorded versions are skipped. Migration versions and names must be unique. The domain migration creates organisations, users, timetable versions and slots, recurring lessons, dated lesson occurrences, and requisitions. It does not seed data or generate occurrences.

MySQL DDL can implicitly commit and is not fully transactional. A failed migration is not recorded as applied, but a migration that fails after some DDL may leave partial schema changes. Review and repair the database before rerunning such a migration; migrations should be small, forward-only, and safe to retry where practical.

The migration identity should have schema-changing privileges scoped only to the Reqsheet database, including `CREATE`, `ALTER`, and `DROP` as future migrations may need to replace or remove objects. The runtime identity should have only application DML privileges and no schema-changing privileges.

The database enforces keys, foreign keys, required values, date/time ranges, allowed slot kinds, allowed requisition states, and simple organisation-local uniqueness. Service validation must enforce non-overlapping timetable-version date ranges, same-version/day slot relationships, contiguous teaching-only lesson spans, occurrence dates matching recurring lessons, cross-organisation consistency, requisition-state/content consistency, and room/teacher conflict detection. Room conflict comparison trims surrounding whitespace and compares case-insensitively; the stored room code is unchanged.

## Timetable configuration services

The configuration services are the intended application path for writing timetable versions, slots, and recurring lessons. `TimetableVersionService` applies the half-open effective-date rule and rejects overlapping versions without truncating existing data. `TimetableSlotService` validates ISO weekdays, positive sequence/period values, allowed kinds, valid non-overlapping times, and coherent sequence/chronological order. `RecurringLessonService` validates organisation ownership, start-slot relationships, contiguous teaching-only spans, non-blank free-text class/room codes, and teacher/room conflicts. The admin editor uses these services and allows recurring-lesson edits/removals only before materialised occurrences exist. These services use `TimetableValidationException` for expected invalid input; PDO/database failures remain operational exceptions.

There is no timetable-version or slot update workflow yet. Recurring lessons can be edited or removed through the admin editor only before materialised historical occurrences exist. Any future broader update design must preserve the immutable boundary after occurrences have been generated and must never rewrite historical snapshots.

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

The public HTTPS routing has been verified by an administrator: `GET /` and `GET /health` succeed, `POST /health` returns 405, and unknown or repository-looking paths return 404. Apache continues to use `FallbackResource /index.php`; the application allowlist prevents that fallback from exposing non-public repository paths.

The skeletal admin timetable editor is available only when the protected environment provides `REQSHEET_ADMIN_KEY` and `REQSHEET_ADMIN_ORGANISATION_ID`. It uses temporary HTTP Basic protection with username `admin` and the externally supplied key as the password; this is a development safeguard, not the application authentication system. It supports staff, room, and day inspection plus validated recurring-lesson creation/editing/removal. Lessons with historical occurrences are immutable.

The skeletal teacher week view is available only when protected configuration provides `REQSHEET_TEACHER_ID`, `REQSHEET_TEACHER_ORGANISATION_ID`, and `REQSHEET_TEACHER_KEY`. It uses temporary HTTP Basic protection with username `teacher` and the externally supplied key as the password. Open `/teacher` (or `/teacher/week`) through the local server or protected vhost to review the current configured week. The view reads materialised dated occurrences; run bounded occurrence generation first if the week has no occurrences. `REQSHEET_FIRST_DAY_OF_WEEK` may be supplied as an ISO weekday number for temporary development review and defaults to `1` (Monday).
