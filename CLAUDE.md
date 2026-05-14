# CLAUDE.md

Stocs Auth is a headless API authentication service. It is the single source of truth for user identity across the STOCS platform (B2B and Bids). No admin UI — B2B and Bids admin panels call these API endpoints.

## Hard Rules (NON-NEGOTIABLE)

These apply to every change in this project. See `.claude/rules/` for full detail.

1. **NO raw DB queries.** No `DB::table(...)`, `DB::select(...)`, `DB::insert(...)`, `DB::update(...)`, `DB::delete(...)` anywhere. Every table has an Eloquent model + repository. If a model/repo doesn't exist for a table, create it — don't shortcut with raw SQL. See `.claude/rules/database.md`.
2. **CORS + security headers are mandatory.** `config/cors.php` with explicit allowed origins (never `*`), `SecurityHeaders` middleware registered globally, feature test asserting headers are present. This is a setup-day requirement, not a launch-day one. See `.claude/rules/security.md`.
3. **No PII in logs — ever.** Log `user_id`, not `email`. This includes audit log `metadata` columns. For failed login / unauthenticated events, log the `user_id` if the user was found, the IP address, and a hashed/truncated identifier — never the raw email or phone. See `.claude/rules/coding-style.md`.
4. **Form Requests for all validation.** Never `$request->validate()` in a controller.
5. **Sanctum tokens must expire.** Set an expiration in `config/sanctum.php` (24h for user tokens). Non-expiring tokens are a security issue.
6. **Admin approval must be enforced on login.** B2B login must check that the user's `user_platforms.status === 'approved'` for the B2B platform before issuing a token. Pending/rejected/suspended users get rejected with a clear status code.

## Quick Commands

```bash
cd src
composer install
php artisan migrate:fresh --seed
php artisan serve
php artisan test
./vendor/bin/pint
```

## Architecture

### Controller -> Service -> Repository -> Model

| Layer | Responsibility |
|-------|----------------|
| Controllers | HTTP request/response only. Thin. |
| Services (`app/Services/`) | Business logic, orchestration |
| Repositories (`app/Repositories/`) | All database queries |
| Models | Relationships, scopes, casts only |

### Key Locations

| Area | Files |
|------|-------|
| Models | `src/app/Models/` — User, UserPlatform, OtpToken, ServiceApiKey, AuthAuditLog |
| Services | `src/app/Services/` — AuthService, OtpService, PasswordResetService, PlatformAccessService, AuditService |
| Repositories | `src/app/Repositories/` |
| Auth Controllers | `src/app/Http/Controllers/Auth/` |
| Admin Controllers | `src/app/Http/Controllers/Admin/` |
| Service Controllers | `src/app/Http/Controllers/Service/` |
| Middleware | `src/app/Http/Middleware/` |
| Mail | `src/app/Mail/` |
| Enums | `src/app/Enums/` — Platform, PlatformRole, PlatformStatus |

## API Endpoints

Full OpenAPI 3.1 spec at [src/docs/openapi.yaml](src/docs/openapi.yaml) — also served at `GET /api/docs/openapi.yaml` (no auth). Import into Postman, Insomnia, or use for client generation (openapi-generator, orval, etc.). Update the spec in the same change as any endpoint contract change.

### Public Auth (`/api/v1/auth/`)
- `POST /register/b2b` �� B2B password registration
- `POST /login/b2b` — B2B password login
- `POST /otp/request` — Request OTP (Bids)
- `POST /otp/verify` — Verify OTP, returns token
- `POST /password/forgot` — Request password reset
- `POST /password/reset` — Reset with token

### Authenticated (`/api/v1/auth/` + Sanctum token)
- `GET /me` — Current user + platforms
- `PUT /me` — Update profile
- `PUT /me/password` — Change password
- `PUT /me/marketing` — Marketing preferences
- `DELETE /me` — GDPR delete
- `POST /logout` — Revoke current token
- `POST /logout/all` — Revoke all tokens

### Admin (`/api/v1/admin/` + admin token)
- `GET /users` — List users (filter by platform, status, role)
- `GET /users/{uuid}` — User detail
- `PUT /users/{uuid}` — Update user
- `POST /users/{uuid}/approve` — Approve platform access
- `POST /users/{uuid}/reject` — Reject platform access
- `POST /users/{uuid}/suspend` — Suspend user
- `POST /users` — Create admin user
- `GET /audit-logs` — Auth audit logs

### Service-to-Service (`/api/v1/service/` + X-Service-Key header)
- `POST /validate-token` — Validate a Sanctum token
- `GET /users/{uuid}` — Lookup user by UUID
- `GET /users/by-email/{email}` — Lookup by email

Issue service keys via: `php artisan auth:issue-service-key <name> <b2b|bids>`. Keys are returned once in `{prefix}.{secret}` form; only the hashed secret is stored. Consumers send the full key in the `X-Service-Key` header.

## Core Concepts

### Platform Access Model
Users have access to platforms via the `user_platforms` pivot table:
- Each record: `user_id + platform (b2b|bids) + role (admin|wholesaler|consumer) + status (pending|approved|rejected|suspended)`
- B2B signup creates: pending B2B + auto-approved Bids
- Bids signup creates: auto-approved Bids only
- Admins get both platforms, both approved

### Token Strategy
- Sanctum personal access tokens, scoped per platform
- Abilities: `['platform:b2b']` or `['platform:bids']`
- B2B/Bids backends validate via `/service/validate-token`

### OTP Auth (Bids)
- 6-digit code, hashed, 10-minute expiry
- Max 3 verification attempts per code
- Max 5 OTP requests per identifier per hour
- New users created on first successful OTP verification

## B2B Migration Plan

Migrating existing B2B users into stocs-auth is a multi-step process.

### Step 1: Add UUID column to B2B

Run the migration in stocs-b2b that adds `auth_user_uuid` to the users table:

```bash
cd stocs-b2b/src
php artisan migrate
```

This runs `2026_04_17_000001_add_auth_user_uuid_to_users_table.php`.

### Step 2: Migrate users into stocs-auth

```bash
cd stocs-auth/src

# Preview what will be migrated (no changes made)
php artisan auth:migrate-b2b-users --dry-run

# Run the migration
php artisan auth:migrate-b2b-users
```

The command:
- Reads all users from the B2B database (via the `b2b` database connection in `config/database.php`)
- Creates each user in stocs-auth with a new UUID, preserving the password hash
- Creates `user_platforms` records: admins get both platforms approved, wholesalers get pending B2B + auto-approved Bids
- Copies `admin_email_preferences` into the B2B platform record's metadata
- Writes the `auth_user_uuid` back to the B2B users table
- Idempotent — safe to re-run, skips users that already exist (matched by email)

### Step 3: Wire B2B to authenticate via stocs-auth

Replace B2B's auth controllers with API calls to stocs-auth. B2B login calls `POST /api/v1/auth/login/b2b`, gets a Sanctum token, stores it in the session. B2B middleware validates tokens via `POST /api/v1/service/validate-token`. Platform-specific data (wizard preferences, delivery locations, watchlists) stays in B2B's database, linked by `auth_user_uuid`.

### Step 4: Dual-write period (optional)

During transition, B2B can write user mutations to both its local table and stocs-auth to keep them in sync. Once B2B is fully cut over, its local users table becomes a profile table with `auth_user_uuid` as the foreign key.

### Database Connection

The `b2b` connection in `config/database.php` points to the B2B SQLite database. In production, update the `B2B_DB_DATABASE` env var to point to the B2B MySQL database instead.

## Running with B2B

Both services must be running for B2B login/register/password reset to work:

```bash
# Terminal 1: Start stocs-auth
cd stocs-auth/src && php artisan serve --port=8098

# Terminal 2: Start stocs-b2b (with auth URL pointing to stocs-auth)
cd stocs-b2b/src && STOCS_AUTH_URL=http://localhost:8098 php artisan serve
```

## Environment

- Local: SQLite at `src/database/database.sqlite`
- Production: MySQL 8.0
- Testing: In-memory SQLite
