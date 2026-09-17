# Reqsheet

Reqsheet is a deliberately simple PHP/MySQL web application for science-department lesson requisitions.

## Current status

This repository contains the initial Reqsheet development foundation. It proves that PHP runs, Composer PSR-4 autoloading works, and the project smoke-test command can execute. The initial application-domain schema and bounded timetable occurrence-generation service are present, but product features, authentication, UI, timetable editing, and deployment configuration are intentionally not implemented.

## Development commands

```sh
composer dump-autoload
composer test
php -S 127.0.0.1:8080 -t public
```

The dependency-free `composer test` command is a temporary smoke test. The database foundation can be exercised with environment variables supplied by a protected shell or host configuration:

```sh
php bin/migrate.php
```

After timetable data exists, bounded occurrence generation can be exercised with explicit inclusive dates:

```sh
php bin/generate-occurrences.php --organisation=1 --version=1 --start=2026-09-01 --end=2026-09-30
```

When Composer can reach Packagist, install the local development dependency with `composer install` and run the PHPUnit suite with `composer test:phpunit`.

`GET /health` checks the runtime database connection and returns only a generic healthy/unhealthy status. The root response remains the initial `Reqsheet ok` smoke response.

The database foundation and initial application-domain schema have been verified against the local MySQL development environment. The development database, separated runtime/migration identities, protected host configuration, metadata migration, domain migration, repeat migration, and `/health` success/method checks have all been exercised. No application services or product features are included yet.

See [SETUP.md](SETUP.md), [PRODUCT_DESIGN.md](PRODUCT_DESIGN.md), [ROADMAP.md](ROADMAP.md), and [SECURITY.md](SECURITY.md) for the initial project direction.
