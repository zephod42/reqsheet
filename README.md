# Reqsheet

Reqsheet is a deliberately simple PHP/MySQL web application for science-department lesson requisitions.

## Current status

This repository contains only the initial development bootstrap. It proves that PHP runs, Composer PSR-4 autoloading works, and the project smoke-test command can execute. Product features, authentication, database schema, and deployment configuration are intentionally not implemented.

## Development commands

```sh
composer dump-autoload
composer test
php -S 127.0.0.1:8080 -t public
```

The dependency-free `composer test` command is a temporary smoke test. When Composer can reach Packagist, install the local development dependency with `composer install` and run the PHPUnit suite with `composer test:phpunit`.

See [SETUP.md](SETUP.md), [PRODUCT_DESIGN.md](PRODUCT_DESIGN.md), [ROADMAP.md](ROADMAP.md), and [SECURITY.md](SECURITY.md) for the initial project direction.
