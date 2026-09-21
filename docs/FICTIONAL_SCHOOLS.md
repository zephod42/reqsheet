# Pumba fictional-school generator

This is a development-only data generator for the existing Pumba `reqsheet_dev`
database. It is not an application feature and is never invoked by HTTP,
migrations, startup, deployment, scheduled tasks, or the normal test suite.

The source command is `bin/generate-fictional-schools.php`. It has independent
hard-coded gates for the hostname `pumba`, database name `reqsheet_dev`, and
the connected MySQL server identity. It also requires the protected local file
`/etc/reqsheet/reqsheet-fictional-generator.json` and the protected manifest
`/var/lib/reqsheet/reqsheet-fictional-schools.json`; both must not be writable
by group or other. No command-line option bypasses these checks, and the
command never accepts a database name, host, authorization token, or manifest
path from the command line.

An administrator must create the local files outside this repository. The auth
JSON contains an arbitrary 32-character-or-longer `execution_authorization`
value and a `passwords` object with one password for each of `brackenmere`,
`ashwick`, and `fenmere`. The manifest starts as `{ "schools": {} }`. Passwords
are used only to create the fictional administrator accounts and are never
written to the repository or manifest. Do not print or publish the auth file.

Run from `/var/www/reqsheet` as the explicit administrator CLI operation:

```sh
php bin/generate-fictional-schools.php --dry-run
php bin/generate-fictional-schools.php
php bin/generate-fictional-schools.php --school=ashwick --dry-run
php bin/generate-fictional-schools.php --school=ashwick --regenerate
```

The first command must be reviewed before the write. A normal rerun is a
no-op for manifest-owned schools. `--regenerate` is allowed only for an exact
manifest entry whose organisation ID, name, and slug still match; it deletes
that one tenant's known child rows in a transaction and recreates it. A slug
that exists without the matching manifest entry is a hard failure. All other
organisations, including the real pilot, are outside the write set.

Profiles use fixed seed data and reference date `2026-09-21`, with dated
occurrences from `2026-08-31` through `2026-10-23`:

- Brackenmere Community School (`brackenmere`): 6 teachers, 4 rooms, 7
  conventional classes, 6 periods, and a small science timetable.
- Ashwick Hall School (`ashwick`): 14 teachers, 10 rooms, and 14 classes using
  exactly L4/U4/L5/M5/U5/L6/U6 conventions.
- Fenmere Academy (`fenmere`): 30 teachers, 20 rooms, 30 classes, 7 periods,
  and a dense timetable suitable for technician filtering and print testing.

Each school gets a claimed three-letter administrator (`EMR`, `JAH`, `RTA`),
awaiting-first-login teachers and technician, rooms, classes, an active
separator-aware timetable, recurring lessons, dated historical/current/future
occurrences, varied planning text, risk-assessment text, and technician room
preferences. Tenant URLs are `https://<slug>.<configured-base-host>/login`;
the base host is deliberately not hard-coded here. The administrator password
is the corresponding local auth-file password. Other staff accounts use the
normal existing awaiting-first-login flow.

The manifest is the only ownership record. It contains school slugs, exact
organisation IDs, and counts; it contains no passwords, recovery keys, pilot
data, or database dump. Keep it local to Pumba and back it up only through the
administrator's protected host process. Never copy generated data or this
authorization configuration to IONOS.
