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
