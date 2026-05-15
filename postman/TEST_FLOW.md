# Stocs Auth — Manual Test Flow

Step-by-step plan for a human tester driving the auth API with Postman. Mirrors the collection at [stocs-auth.postman_collection.json](stocs-auth.postman_collection.json). For the automated smoke test that exercises the same flow against a throwaway SQLite database, see [smoke.sh](smoke.sh).

---

## 0. Setup

Bring the API up with a fresh database so the seeded demo users exist:

```bash
cd stocs-auth/src
php artisan migrate:fresh --seed
php artisan serve --port=8098
```

Then in a second terminal, tail the log — OTP codes and password-reset tokens land here when `MAIL_MAILER=log` (the default in `.env.example`):

```bash
tail -f stocs-auth/src/storage/logs/laravel.log
```

Issue a service-to-service key (you will need it for the Service folder):

```bash
cd stocs-auth/src
php artisan auth:issue-service-key local-smoke b2b
# Copy the printed "sk_xxxx.yyyy" value — shown ONCE.
```

In Postman, **Import** both files from this folder, select the **stocs-auth Local** environment, and paste the service key into the `serviceKey` env var.

### Seeded credentials (from [DemoUsersSeeder.php](../database/seeders/DemoUsersSeeder.php))

| User | Email | Password | Notes |
|------|-------|----------|-------|
| Admin | `admin@stocs.test` | `testing1!` | Super-admin, both platforms approved |
| Wholesaler | `wholesale@stocs.test` | `testing1!` | B2B approved + Bids |
| Bidder | `bidder@stocs.test` | — | OTP-only |
| Bidder 2 | `bidder2@stocs.test` | — | OTP-only |

---

## Flow 1 — B2B registration → admin approval → login

Demonstrates the gated B2B onboarding (pending → approved by admin → can issue tokens).

| # | Postman request | Expected | What to look at |
|---|-----------------|----------|------------------|
| 1 | Public Auth → **Health** | 200, `{"status":"ok"}` | Sanity check the server. |
| 2 | Public Auth → **Register B2B** | **201**, response has `token` + `user.uuid` | Body captures `userToken` and `userUuid` into the environment. The new user's B2B platform record is `pending`; their Bids access is auto-approved. |
| 3 | Public Auth → **Login B2B** *(using the just-registered email)* — change body to `new-wholesaler@stocs.test` / `TestPassword1234` | **403** with `status: "pending"` | Confirms the approval gate is enforced. Switch the body back when done. |
| 4 | Public Auth → **Login Admin** | 200, captures `adminToken` | Admin auth uses the same `/login/b2b` endpoint. |
| 5 | Admin → **List Users** *(query: `platform=b2b&status=pending`)* | 200, includes `new-wholesaler@stocs.test` | Copy the new user's UUID into the `userUuid` env var. |
| 6 | Admin → **Approve** | 200 | Body: `{"platform":"b2b"}`. The user's B2B status flips to `approved`. |
| 7 | Public Auth → **Login B2B** *(new wholesaler again)* | 200, token issued | Approval gate now lets them through. |
| 8 | Me → **Get Me** | 200, includes `platforms[]` with `b2b: approved` and `bids: approved` | Uses the B2B user's `userToken`. |

> If step 2 fails with 422, re-seed the DB (`migrate:fresh --seed`) — the new email is probably already taken from a previous run.

---

## Flow 2 — Approved wholesaler day-to-day

Quick happy path for a returning B2B user.

| # | Request | Expected |
|---|---------|----------|
| 1 | Public Auth → **Login B2B** *(default body: `wholesale@stocs.test`)* | 200, captures `userToken` |
| 2 | Me → **Get Me** | 200, `email = wholesale@stocs.test`, B2B = approved |
| 3 | Me → **Update Profile** | 200, name updated |
| 4 | Me → **Update Marketing** | 200, preferences echoed back |
| 5 | Me → **Update Password** | 200 — current token stays valid, others revoked |
| 6 | Me → **Logout** | 204 / 200, current token revoked |
| 7 | Me → **Get Me** *(same token)* | 401 — token now revoked |

> Reseed before running this flow again so step 5 doesn't lock you out with a password you've thrown away.

---

## Flow 3 — Bids OTP login

OTP is the only auth mechanism for Bids consumers.

| # | Request | Expected | Notes |
|---|---------|----------|-------|
| 1 | Public Auth → **Request OTP** *(default identifier: `bidder@stocs.test`)* | **202** Accepted | Always 202 — the response never reveals whether the identifier is valid. |
| 2 | *(tail the log)* | A line like `Your login code for Stocs Bids is:` followed by a 6-digit `<h1>`. | The mail driver writes the rendered template into the log. Copy the digits into the `otpCode` env var. |
| 3 | Public Auth → **Verify OTP** | 200, captures `userToken` and `userUuid` | The returned token has the `platform:bids` ability. |
| 4 | Me → **Get Me** | 200, `platforms` includes Bids = approved | First-time OTP verification creates the user on the fly. |

### Negative cases worth touching

- **Wrong code** → 422. After 3 wrong attempts the code is dead — request a new one. (The response body is identical for wrong, locked, and expired codes; the API does not expose an `attempts_remaining` field today.)
- **Expired code** → 422. Codes live 10 minutes.
- **Verify OTP rate limit** → 6th call to **Verify OTP** within a minute returns 429 from `throttle:auth` (5/min per IP), even if the code is correct. This is correct behaviour — the frontend handles it as "too many attempts, wait a minute". Do not interpret it as a wrong-code response.
- **Request OTP rate limit** → 6th request to the same identifier inside an hour returns 429.

---

## Flow 4 — Password reset

| # | Request | Expected | Notes |
|---|---------|----------|-------|
| 1 | Public Auth → **Forgot Password** | **200** every time | Response is intentionally constant — does not disclose user existence. |
| 2 | *(tail the log)* | Reset link with `?token=<token>&email=<urlencoded>` | Pull the `token` value out of the URL into the `resetToken` env var. |
| 3 | Public Auth → **Reset Password** | 200 | Body uses `resetToken`. On success, every existing Sanctum token for that user is revoked. |
| 4 | Public Auth → **Login B2B** *(new password)* | 200 | Sanity check the new password works. |
| 5 | Me → **Get Me** *(any old token from before step 3)* | 401 | Confirms token revocation. |

### Negative cases

- **Bad/expired token** → 422.
- **Token re-use** → 422 (single-use).

---

## Flow 5 — Admin moderation

Pre-req: `adminToken` set (Flow 1 step 4). All requests live in the **Admin** folder, which overrides the collection's bearer auth to use `adminToken`.

| # | Request | Expected | Notes |
|---|---------|----------|-------|
| 1 | Admin → **List Users** *(filter `platform=b2b&status=pending`)* | 200, paginated | Set `per_page=20` to keep responses small. |
| 2 | Admin → **Get User** *(`{{userUuid}}`)* | 200 | Full user detail incl. all platform records and audit timestamps. |
| 3 | Admin → **Update User** | 200, `name` updated | Admin-side profile edit. |
| 4 | Admin → **Approve** | 200 | Body `{"platform":"b2b"}`. Idempotent on already-approved users. |
| 5 | Admin → **Reject** | 200 | Body `{"platform":"b2b","reason":"…"}`. Triggers the rejection email. |
| 6 | Admin → **Suspend** | 200 | Suspends the platform record AND revokes every Sanctum token for the user. |
| 7 | Admin → **Create Admin** | 201, new admin user returned | `is_super_admin: true` only honoured if the caller is themselves a super-admin. |
| 8 | Admin → **Audit Logs** | 200 | All the actions above should appear here. Filter via `user_id`, `action`, `platform`. |

### Negative cases

- Hit any admin route with `userToken` (non-admin) → **403**.
- Hit any admin route with no token → **401**.

---

## Flow 6 — Service-to-service

Pre-req: `serviceKey` env var populated (see step 0). The Service folder sets `auth: noauth` — auth is via the `X-Service-Key` header.

| # | Request | Expected | Notes |
|---|---------|----------|-------|
| 1 | Service → **Validate Token** *(body: `{"token":"{{userToken}}"}`)* | 200, body includes `user` + `abilities: ["platform:b2b"]` | This is what the B2B/Bids backends call on every request to authenticate the incoming bearer. |
| 2 | Service → **Get User by UUID** | 200, full user record | Used for backend-to-backend user lookups. |
| 3 | Service → **Get User by Email** | 200 | ⚠️ Email is in the URL — flagged in the security review. Likely to move to `POST /service/users/lookup` with email in body; update this collection when that change ships. |
| 4 | Service → **Mint Test Users** *(dev-only)* | 200, returns `count` minted users with their Bids tokens | Blocked in production. Useful for load-testing Bids without a fully manual OTP loop. |

### Negative cases

- Wrong key (`X-Service-Key: wrong.value`) → **401**.
- Right key, wrong platform scope (e.g. a Bids key calling a B2B-scoped lookup) → **403**.
- 601th call in a minute → **429**.

---

## Flow 7 — GDPR delete

Destructive; run this last (or against a reseeded DB).

| # | Request | Expected |
|---|---------|----------|
| 1 | Me → **Delete Account** | 200/204 |
| 2 | Me → **Get Me** *(same token)* | 401 — tokens revoked |
| 3 | Public Auth → **Login B2B** *(same email)* | 401 — user soft-deleted, email anonymised |
| 4 | Admin → **Get User** *(`{{userUuid}}`)* | 200 with anonymised PII (`deleted_at` set, email rewritten to `deleted-<uuid>@…`) |

---

## Coverage matrix

| Endpoint | Flow |
|----------|------|
| `GET /api/health` | 0 |
| `POST /api/v1/auth/register/b2b` | 1 |
| `POST /api/v1/auth/login/b2b` | 1, 2, 4 |
| `POST /api/v1/auth/otp/request` | 3 |
| `POST /api/v1/auth/otp/verify` | 3 |
| `POST /api/v1/auth/password/forgot` | 4 |
| `POST /api/v1/auth/password/reset` | 4 |
| `GET /api/v1/auth/me` | 1, 2, 3, 7 |
| `PUT /api/v1/auth/me` | 2 |
| `PUT /api/v1/auth/me/password` | 2 |
| `PUT /api/v1/auth/me/marketing` | 2 |
| `DELETE /api/v1/auth/me` | 7 |
| `POST /api/v1/auth/logout` | 2 |
| `POST /api/v1/auth/logout/all` | covered indirectly via reset (Flow 4) |
| `GET /api/v1/admin/users` | 1, 5 |
| `GET /api/v1/admin/users/{uuid}` | 5, 7 |
| `PUT /api/v1/admin/users/{uuid}` | 5 |
| `POST /api/v1/admin/users/{uuid}/approve` | 1, 5 |
| `POST /api/v1/admin/users/{uuid}/reject` | 5 |
| `POST /api/v1/admin/users/{uuid}/suspend` | 5 |
| `POST /api/v1/admin/users` | 5 |
| `GET /api/v1/admin/audit-logs` | 5 |
| `POST /api/v1/service/validate-token` | 6 |
| `GET /api/v1/service/users/{uuid}` | 6 |
| `GET /api/v1/service/users/by-email/{email}` | 6 |
| `POST /api/v1/service/test-users/mint` | 6 |

---

## When something fails

1. **Re-seed** — most state-related flakiness (`422 email taken`, stale tokens) clears with `php artisan migrate:fresh --seed`.
2. **Check the log** — `tail -f storage/logs/laravel.log`. Mail driver is `log`, queue is `database` by default. If OTP emails don't appear, the queue worker isn't running — either start one (`php artisan queue:work`) or set `QUEUE_CONNECTION=sync` in `.env`.
3. **Check the env vars** — most "401 / 403" issues are because `userToken` got overwritten by a later capture script (e.g. running Verify OTP populates the same variable as Login B2B).
4. **Check the spec** — [docs/openapi.yaml](../docs/openapi.yaml) is the contract. If a response shape doesn't match, file it as a bug or update the spec.
