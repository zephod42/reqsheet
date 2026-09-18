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

When implementing the agreed UI/admin work:
- keep the first UI deliberately skeletal and easy to change for pilot iteration;
- prefer server-rendered HTML and minimal JavaScript;
- preserve effective-dated timetable-version semantics and historical occurrence snapshots;
- keep timetable CSV parsing deterministic and limited to the canonical Reqsheet CSV format;
- do not invent MIS-specific importers, arbitrary-file inference, heuristic stripping, regex guessing, or an AI API for timetable import;
- treat any external AI-assisted CSV conversion as outside the application.
- preserve the agreed primary landing views: Teacher is week-oriented and Technician is day-oriented; Admin is an additional capability, not an operational role.
- preserve the agreed pilot UI decisions in `PRODUCT_DESIGN.md`: restrained black-and-white Reqsheet chrome, technical timetable typography, setup gating, persistent-login intent, teacher class colours, technician neutral/black-and-white output, and large lesson-editing pop-outs.

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

For any task that changes public routes, forms, signup/login/logout, sessions/authentication, redirects, setup gating, settings/onboarding, or teacher/technician interactive routes, completion also requires a live HTTP smoke test against the Apache-served Reqsheet application from Pumba. Prefer `curl` with `--resolve reqsheet.duckdns.org:443:127.0.0.1` so the real hostname, TLS, and Apache vhost behaviour are exercised. Use a cookie jar and retrieve/submit CSRF tokens when the application uses them. Unit and integration tests do not replace this smoke test, and direct PHP route rendering is not equivalent. If live HTTP testing is genuinely impossible, explicitly report the limitation and do not claim end-to-end route verification.

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
