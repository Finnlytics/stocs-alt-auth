# stocs-auth Postman Collection

Postman v2.1 export of the auth API. Mirrors the Bruno collection at
[../bruno/](../bruno/) and the OpenAPI spec at
[../../src/docs/openapi.yaml](../../src/docs/openapi.yaml).

Internal / test-only — do not publish.

## Files

| File                                            | What it is                                   |
|-------------------------------------------------|----------------------------------------------|
| `stocs-auth.postman_collection.json`            | The collection (Public Auth / Me / Admin / Service) |
| `stocs-auth.Local.postman_environment.json`     | Environment with `baseUrl`, tokens, seed creds |

## Import

1. Postman → `Import` → drop both JSON files in.
2. Top-right environment dropdown → select **stocs-auth Local**.
3. Start the API: `cd src && php artisan serve --port=8098`.

## Auth model

- The collection's default auth is `Bearer {{userToken}}`.
- The **Admin** folder overrides that to `Bearer {{adminToken}}`.
- The **Service** folder overrides to no-auth — service routes use the
  `X-Service-Key` header instead, which is set per-request.

## Token capture scripts

Login / register / verify-OTP requests have post-response scripts that
auto-populate the relevant env var:

| Request                       | Sets                         |
|-------------------------------|------------------------------|
| Public Auth → Login B2B       | `userToken`, `userUuid`      |
| Public Auth → Login Admin     | `adminToken`                 |
| Public Auth → Register B2B    | `userToken`, `userUuid`      |
| Public Auth → Verify OTP      | `userToken`, `userUuid`      |

Other vars (`serviceKey`, `resetToken`, `otpCode`) are populated manually.

## Typical flows

**B2B user**: Login B2B → Get Me → Update Profile / Password / Marketing.

**Admin**: Login Admin → List Users (`status=pending`) → paste a UUID into
`userUuid` → Approve / Reject / Suspend → Audit Logs.

**Service-to-service**: Issue a key with
`cd src && php artisan auth:issue-service-key local-test b2b`, paste the
`prefix.secret` into `serviceKey`, then fire Validate Token / Get User by UUID.

**Bids OTP**: Request OTP → tail `src/storage/logs/laravel.log` for the code
→ paste into `otpCode` → Verify OTP.

## Source of truth

Endpoint shapes mirror `src/docs/openapi.yaml`. When an endpoint contract
changes, update OpenAPI for code-gen and update this collection (and the
Bruno one) for manual testing.
