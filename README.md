# Reqsheet

Reqsheet is a deliberately simple PHP/MySQL web application for science-department lesson requisitions.

## Current status

This repository contains the working Reqsheet pilot: tenant-hosted authentication, account recovery, organisation settings, people management, timetable import/editing, teacher planning, and technician views on a conventional PHP/MySQL foundation.

## Development commands

```sh
composer dump-autoload
composer test
php -S 127.0.0.1:8080 -t public
```

The dependency-free `composer test` command runs the application regression suite. The database foundation can be exercised with environment variables supplied by a protected shell or host configuration:

```sh
php bin/migrate.php
```

After timetable data exists, bounded occurrence generation can be exercised with explicit inclusive dates:

```sh
php bin/generate-occurrences.php --organisation=1 --version=1 --start=2026-09-01 --end=2026-09-30
```

When Composer can reach Packagist, install the local development dependency with `composer install` and run the PHPUnit suite with `composer test:phpunit`.

`GET /health` checks the runtime database connection and returns only a generic healthy/unhealthy status. The root response remains the initial `Reqsheet ok` smoke response.

The optional isolated MySQL integration suite exercises migrations and database-backed service invariants; see `SETUP.md` for its strict `reqsheet_test` safety gate and configuration.

See [SETUP.md](SETUP.md), [PRODUCT_DESIGN.md](PRODUCT_DESIGN.md), [ROADMAP.md](ROADMAP.md), and [SECURITY.md](SECURITY.md) for the initial project direction.
