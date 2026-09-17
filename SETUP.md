# Local setup

## Requirements

- PHP 8.2 or newer
- Composer 2.x
- MySQL client/server access when database work begins

The current bootstrap uses PHP and Composer only. It does not require a database connection, web-server configuration, or privileged host changes.

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

For a local smoke test of the public entry point:

```sh
php -S 127.0.0.1:8080 -t public
```

Stop the development server with `Ctrl-C`. Production deployment and Apache configuration are intentionally outside this bootstrap.
