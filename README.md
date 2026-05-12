# Stocs Auth — Service

Headless API authentication service. Single source of truth for user identity across stocs-b2b and stocs-bids. No admin UI — other services' admin panels call these endpoints.

For architecture, platform model, and the B2B migration plan, see [`../CLAUDE.md`](../CLAUDE.md).

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
