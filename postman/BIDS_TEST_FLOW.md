# Stocs Auth — Bids Test Flow

Step-by-step Postman test plan covering every auth path a Bids consumer can hit.
For the broader auth surface (B2B registration, admin moderation, password reset) see [TEST_FLOW.md](TEST_FLOW.md).

---

## 0. Setup

```bash
cd stocs-auth/src
php artisan migrate:fresh --seed
php artisan serve --port=8098
```

Tail the log in a second terminal (OTP codes land here when `MAIL_MAILER=log`):

```bash
tail -f stocs-auth/src/storage/logs/laravel.log
```

Issue a service key for the service-layer flows:

```bash
php artisan auth:issue-service-key bids-smoke b2b
# Copy the printed "sk_xxxx.yyyy" — shown ONCE.
```

In Postman: **Import** both JSON files from this folder, select **stocs-auth Local**, paste the service key into `serviceKey`.

### Seeded Bids users

| User | Email | Notes |
|------|-------|-------|
| Bidder | `bidder@stocs.test` | Pre-existing Bids user, OTP-only |
| Bidder 2 | `bidder2@stocs.test` | Pre-existing Bids user, OTP-only |

New email addresses entered during these flows will be auto-created as Bids users on first OTP verify.

---

## Flow 1 — New user first-time OTP login

Demonstrates auto-registration: a brand-new email address gets a Bids account created on first successful OTP verify.

| # | Request | Expected | Notes |
|---|---------|----------|-------|
| 1 | Public Auth → **Health** | 200, `{"status":"ok"}` | Sanity check. |
| 2 | Public Auth → **Request OTP** — change `identifier` to `newbidder@example.test` | **202** | Response is always 202; never reveals whether the identifier is known. |
| 3 | *(tail log)* | Line containing `Your login code for Stocs Bids is:` followed by a 6-digit number | Copy the digits into `otpCode` env var. |
| 4 | Public Auth → **Verify OTP** — change `identifier` to `newbidder@example.test` | 200, `is_new_user: true`, captures `userToken` + `userUuid` | Token has `platform:bids` ability. |
| 5 | Me → **Get Me** | 200, `platforms` includes `bids: approved`, no B2B entry | Uses the Bids `userToken`. |

> If step 4 returns 422, the code is wrong, expired, or attempt-exhausted (the response body is identical in all three cases). Re-run step 2 to get a fresh code.

---

## Flow 2 — Returning Bids user login

Happy path for an existing Bids account (`bidder@stocs.test`).

| # | Request | Expected |
|---|---------|----------|
| 1 | Public Auth → **Request OTP** *(default `bidderEmail`)* | 202 |
| 2 | *(tail log)* | 6-digit code → paste into `otpCode` |
| 3 | Public Auth → **Verify OTP** | 200, `is_new_user: false`, new `userToken` issued |
| 4 | Me → **Get Me** | 200, `email: bidder@stocs.test`, `bids: approved` |
| 5 | Me → **Logout** | 204/200 — current token revoked |
| 6 | Me → **Get Me** *(same token)* | 401 — token no longer valid |

---

## Flow 3 — OTP negative cases

Run these after Flow 2 (a fresh `bidder@stocs.test` OTP code is needed).

| # | Scenario | Request | Expected |
|---|----------|---------|----------|
| 1 | Wrong code | **Verify OTP** — set `otpCode` to `000000` | 422 |
| 2 | Wrong code again | Same request | 422 |
| 3 | Exhaust attempts (3rd wrong) | Same request | 422 — code is locked after 3 wrong attempts |
| 4 | Locked code | **Verify OTP** with the real code from step 0 | 422 — code is dead even if value is correct; request a new one |
| 5 | Expired code | **Request OTP**, wait 11+ minutes, **Verify OTP** | 422 |
| 6 | IP throttle on Verify | 6th call to **Verify OTP** within a minute (regardless of code value) | **429** — `throttle:auth` middleware caps the `/auth/*` group at 5/min per IP. The frontend should map this to a "wait a minute" message and not a "wrong code" message. |
| 7 | Rate limit on Request | Fire **Request OTP** for the same identifier 7 times rapidly | 6th request returns **429** — `throttle:otp` middleware (5/hour, 3/min per identifier) |
| 8 | Unknown identifier still 202 | **Request OTP** — `identifier: nobody@nowhere.test` | **202** — response is indistinguishable from valid identifier |

> After exhausting attempts (step 3), call **Request OTP** again to get a fresh code before continuing.
>
> Note: the API does **not** currently return `attempts_remaining` — every wrong/locked/expired case returns the same `422 {"message": "Invalid or expired OTP code."}` body. The frontend treats them all identically and prompts the user to request a new code.

---

## Flow 4 — Service layer: validate a Bids token

Simulates what the Bids backend does on every incoming request — it calls `POST /v1/service/validate-token` to authenticate the bearer.

Pre-req: `userToken` populated from Flow 1 or 2, `serviceKey` env var set.

| # | Request | Expected | Notes |
|---|---------|----------|-------|
| 1 | Service → **Validate Token** — body `{"token":"{{userToken}}"}` | 200, `abilities: ["platform:bids"]`, `user.platforms.bids: approved` | Core contract: Bids API trusts this response to authorise the request. |
| 2 | Service → **Get User by UUID** | 200, full user record | Backend-to-backend lookup by `userUuid`. |
| 3 | Service → **Validate Token** — body `{"token":"invalid.token.value"}` | 401 | Bad token rejected. |
| 4 | Service → **Validate Token** — remove `X-Service-Key` header | 401 | Service routes require the key. |
| 5 | Service → **Validate Token** — `X-Service-Key: wrong.value` | 401 | Wrong key rejected. |

---

## Flow 5 — Admin: manage Bids platform access

Pre-req: `adminToken` set (run Public Auth → **Login Admin** first).

| # | Request | Expected | Notes |
|---|---------|----------|-------|
| 1 | Admin → **List Users** *(filter `platform=bids&status=approved`)* | 200, includes seeded bidders | Set `per_page=20`. |
| 2 | Admin → **Get User** *(`{{userUuid}}` = bidder from Flow 1)* | 200, includes `platforms.bids: approved` | |
| 3 | Admin → **Suspend** — body `{"platform":"bids"}` | 200 | Suspends the Bids platform record AND revokes all Sanctum tokens for the user. |
| 4 | Me → **Get Me** *(bidder's `userToken`)* | 401 — token revoked by suspend | |
| 5 | Public Auth → **Verify OTP** *(fresh OTP for same bidder)* | 200 but `platforms.bids: suspended` | OTP still issues a token; the Bids API should reject it based on status. |
| 6 | Service → **Validate Token** *(new token from step 5)* | 200 — token is cryptographically valid; `platforms.bids: suspended` | Bids API must check the status field, not just token validity. |
| 7 | Admin → **Approve** — body `{"platform":"bids"}` | 200 — status returns to `approved` | Idempotent on already-approved users. |
| 8 | Admin → **Audit Logs** | 200, suspend + approve actions appear | Filter by `user_id` to isolate this user's history. |

---

## Flow 6 — Bids user logout / session management

| # | Request | Expected | Notes |
|---|---------|----------|-------|
| 1 | *(Ensure `userToken` is a live Bids token from Flow 2)* | — | |
| 2 | Me → **Logout** | 204/200 | Current device token revoked. |
| 3 | Me → **Get Me** | 401 | |
| 4 | *(Get a new token via OTP)* | — | Request OTP → Verify OTP for same bidder |
| 5 | Me → **Logout All** | 204/200 | Revokes all tokens across all devices for this user. |
| 6 | Service → **Validate Token** *(any previous token for this user)* | 401 | Confirms Logout All revokes from service perspective too. |

---

## Flow 7 — Mint test users (dev-only)

Shortcut that mints N Bids-scoped tokens without going through the OTP loop. Useful for load testing or seeding the Bids API.

Pre-req: `serviceKey` set. Blocked in `APP_ENV=production`.

| # | Request | Expected | Notes |
|---|---------|----------|-------|
| 1 | Service → **Mint Test Users** — body `{"count":3}` | 200, `users` array with 3 entries, each with `token` and `uuid` | Each token has `platform:bids` ability. |
| 2 | Service → **Validate Token** *(token from `users[0].token`)* | 200, `platforms.bids: approved` | Confirms minted tokens pass the real validation path. |
| 3 | Service → **Mint Test Users** — body `{"count":1}` in production env | 403 or 404 | Guard should block this in prod. |

---

## Coverage matrix

| Endpoint | Flows |
|----------|-------|
| `GET /api/health` | 1 |
| `POST /api/v1/auth/otp/request` | 1, 2, 3 |
| `POST /api/v1/auth/otp/verify` | 1, 2, 3, 5 |
| `GET /api/v1/auth/me` | 1, 2, 6 |
| `POST /api/v1/auth/logout` | 2, 6 |
| `POST /api/v1/auth/logout/all` | 6 |
| `GET /api/v1/admin/users` | 5 |
| `GET /api/v1/admin/users/{uuid}` | 5 |
| `POST /api/v1/admin/users/{uuid}/approve` | 5 |
| `POST /api/v1/admin/users/{uuid}/suspend` | 5 |
| `GET /api/v1/admin/audit-logs` | 5 |
| `POST /api/v1/service/validate-token` | 4, 5, 6 |
| `GET /api/v1/service/users/{uuid}` | 4 |
| `POST /api/v1/service/test-users/mint` | 7 |

---

## When something fails

1. **422 on Verify OTP** — either wrong code, exceeded attempts, or expired. Re-run Request OTP to get a fresh code.
2. **429 on Verify OTP** — the `throttle:auth` middleware caps the whole `/auth/*` group at 5/min per IP. Hitting Verify OTP six times in a minute (wrong codes, retries, whatever) returns 429 *regardless of whether the code is right*. This is the correct backend behaviour; the frontend handles it with a "too many attempts, wait a minute" message.
3. **401 on Service routes** — check `X-Service-Key` header is set and `serviceKey` env var is not empty. Keys are single-use-printout; if you lost it, issue a new one with `php artisan auth:issue-service-key`.
4. **Stale `userToken`** — running Verify OTP for a new user overwrites `userToken`. If you need the old token back, re-run the OTP flow for that user.
5. **429 on Request OTP** — the `throttle:otp` middleware caps at 5 requests per hour per identifier. Switch to a different email or reseed (`migrate:fresh --seed`) to reset rate limit state.
6. **Suspend not revoking tokens** — ensure the queue worker is running (`php artisan queue:work`) or set `QUEUE_CONNECTION=sync` in `.env`. Token revocation may be queued.
