# Stocs Auth — Service

Headless API authentication service. Single source of truth for user identity across stocs-b2b and stocs-bids. No admin UI — other services' admin panels call these endpoints.

## Quick commands

```bash
composer install
cp .env.example .env
php artisan key:generate

# Fresh DB (SQLite)
php artisan migrate:fresh --seed

# Run tests
php artisan test

# Code style
./vendor/bin/pint
```

## CI

Every push to `main` runs a `validate` job that must pass before anything deploys:
`composer validate --strict`, `composer audit --locked --no-dev`, `pint --test`, a
`route:list` boot check, and the test suite. See
[.github/workflows/deploy.yml](.github/workflows/deploy.yml).

Two things to know:

- **The audit is blocking.** A newly published advisory in a transitive dependency can therefore
  break deploys with no change on our side. Clear it with a targeted
  `composer update <pkg> --with-dependencies` rather than disabling the check.
- **larastan is not wired up yet.** stocs-b2b's CI runs phpstan; neither repo here has it installed.
  Adding `larastan/larastan` plus a `phpstan.neon` to both repos is deliberate future work, not an
  oversight.

The suite needs no `.env`: `phpunit.xml` supplies a throwaway `APP_KEY`, so `vendor/bin/phpunit`
works on a fresh clone. CI still writes one from `.env.example` because the boot check should boot
with the config the app actually uses.

## Dev

Default port for this service in the Stocs dev workflow is **8098**:

```bash
php artisan serve --port=8098
```

Or, for the full stocs-bids stack (this service + bids api + queue + reverb + frontend) from a single terminal:

```bash
cd ../../stocs-bids && ./dev.sh
```

## API surfaces

| Prefix                 | Purpose                                              | Auth                       |
|------------------------|------------------------------------------------------|----------------------------|
| `POST /api/v1/auth/…`  | Register, login, OTP, password reset                 | Public                     |
| `GET/PUT/DELETE /api/v1/auth/me` | Profile + logout                           | Sanctum                    |
| `GET /api/v1/admin/…`  | Admin management (users, approvals, audit log)       | Admin Sanctum token        |
| `POST /api/v1/service/…` | Service-to-service (token validation, user lookup) | `X-Service-Key` header     |

See [`../CLAUDE.md`](../CLAUDE.md) § **API Endpoints** for the full list.

## Environment

| Local   | SQLite at `database/database.sqlite` |
| Prod    | MySQL 8.0                            |
| Testing | In-memory SQLite (PHPUnit)           |

## Auth flavours

- **B2B** — password auth, admin approval workflow (`user_platforms.status = pending → approved`)
- **Bids** — OTP-only, auto-approved on first successful verify (no admin review)
- **Admin** — both platforms approved, separate login endpoint, short-lived tokens

Sanctum tokens are scoped per-platform via abilities (`platform:b2b` / `platform:bids`). B2B and Bids backends validate incoming tokens by calling `POST /api/v1/service/validate-token` with their service key.

## Troubleshooting

### Nobody can log in, but the service returns 200s

The queue worker is not running. All five mailables implement `ShouldQueue`, including
`OtpCodeEmail`, and consumer login is OTP-only, so with no worker no code is ever delivered.
Auth itself looks perfectly healthy. This is the quietest failure in the platform.

### The load-test mint endpoint returns 403 on a non-production environment

`MintTestUsersRequest::authorize()` returns `! app()->isProduction()`, so the endpoint is
gated on `APP_ENV`. Staging running `APP_ENV=production` blocks it, which also means bids'
`bids:simulate-http` harness cannot mint tokens against that environment.

### Every test dies with MissingAppKeyException

Historically the suite needed a `.env`. `phpunit.xml` now supplies a throwaway `APP_KEY`, so
`vendor/bin/phpunit` passes on a fresh clone with none. If you still see this, check for a
stale `phpunit.xml`.

### Tests fail en masse after you ran something unrelated

Check for exported variables in your shell. `phpunit.xml` `<env>` entries only apply when the
variable is not already set, so an exported `APP_KEY` from testing a deploy script replaces
the test config for the session. Use `env -u APP_KEY php artisan test`.

### "Class ... not found" for a package that is in composer.json

The local `vendor/` is stale against `composer.lock`. Run `composer install`.

### A production env var set in the DO dashboard has vanished

`doctl apps update --spec` replaces the entire spec, so anything absent from the rendered spec
is deleted on the next deploy. Put it in `.env.*.example` or GitHub Secrets. See
[.claude/docs/deployment.md](.claude/docs/deployment.md).

### The documented B2B MySQL migration will not work as written

`CLAUDE.md` says to point `B2B_DB_DATABASE` at the B2B MySQL database in production, but the
`b2b` connection in `config/database.php` is hardcoded `'driver' => 'sqlite'`. Pointing it at
MySQL needs a code change, not an env var. Only affects the one-off
`auth:migrate-b2b-users` command.
