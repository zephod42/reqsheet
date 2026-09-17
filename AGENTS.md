# Reqsheet Agent Instructions

## Project scope

You are working on Reqsheet.

Your project workspace is:

    /var/www/reqsheet

Do not modify unrelated projects or system configuration.

## Security boundaries

- Never use or attempt to obtain sudo.
- Never modify `/var/www/ynsee`.
- Never access or search for Ynsee credentials or secrets.
- Treat `/etc/reqsheet` as strictly out of bounds.
- Never search for, display, copy, commit, or expose credentials or secrets.
- Never weaken filesystem permissions to make a task easier.
- Never use world-writable permissions such as `777`.
- Do not modify Apache, PHP, MySQL, firewall, system-user, package, or other host configuration directly.
- If privileged host work is required, report the exact administrator action needed and stop at that boundary.

## Development principles

Reqsheet should remain deliberately simple.

Prefer:
- conventional PHP
- MySQL
- PDO
- server-rendered HTML
- minimal JavaScript
- straightforward migrations
- automated tests
- excellent browser printing

Avoid unnecessary:
- frameworks
- SPA architecture
- microservices
- realtime infrastructure
- complex abstractions
- dependencies
- product scope expansion

Do not invent product requirements merely because they simplify implementation.

## Quality

Before considering a task complete:

- inspect the existing implementation first;
- make only relevant changes;
- run appropriate tests/checks;
- fix routine failures;
- run `git diff --check`;
- report exactly what changed;
- report exactly what tests/checks were run;
- do not claim anything passed unless it actually ran.

## Git

- Never commit secrets, `.env` files, credentials, logs, caches, or runtime data.
- Keep commits bounded and descriptive.
- Do not rewrite published history unless explicitly instructed.
- Do not push until remote repository access has deliberately been configured.

## Canonical documents

As they are created, treat these as authoritative:

- `AGENTS.md`
- `PRODUCT_DESIGN.md`
- `ROADMAP.md`
- `SETUP.md`
- `SECURITY.md`

If implementation and canonical documentation disagree, identify the discrepancy rather than silently choosing one.
