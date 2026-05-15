#!/usr/bin/env bash
#
# stocs-auth smoke test.
#
# Stands up an isolated SQLite-backed copy of the auth API on an ephemeral port,
# runs the Postman collection against it via Newman, scraping the log between
# requests to capture OTP codes and password-reset tokens. Cleans up everything
# on exit.
#
# Usage:
#   ./smoke.sh                 # full smoke
#   SMOKE_PORT=8200 ./smoke.sh # override port (default 8198)
#   SMOKE_KEEP=1 ./smoke.sh    # keep the tmp dir on exit for debugging
#
# Requirements: php, newman (`npm i -g newman`), jq, curl.

set -euo pipefail

# ──────────────────────────────────────────────────────────────────────────────
# Paths + config
# ──────────────────────────────────────────────────────────────────────────────

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$(cd "$HERE/.." && pwd)"
COLLECTION="$HERE/stocs-auth.postman_collection.json"
ENV_TEMPLATE="$HERE/stocs-auth.Local.postman_environment.json"

TMP="$(mktemp -d -t stocs-auth-smoke.XXXXXX)"
DB="$TMP/database.sqlite"
SERVE_LOG="$TMP/serve.log"
ENV_RUNTIME="$TMP/env.json"
ENV_DOTENV="$SRC/.env.smoke"
PORT="${SMOKE_PORT:-8198}"
BASE="http://127.0.0.1:$PORT"

SERVE_PID=""
PHASE_COUNT=0
FAIL_COUNT=0

# ──────────────────────────────────────────────────────────────────────────────
# Cleanup
# ──────────────────────────────────────────────────────────────────────────────

cleanup() {
  local exit_code=$?
  if [[ -n "$SERVE_PID" ]] && kill -0 "$SERVE_PID" 2>/dev/null; then
    kill "$SERVE_PID" 2>/dev/null || true
    wait "$SERVE_PID" 2>/dev/null || true
  fi
  rm -f "$ENV_DOTENV"
  if [[ "${SMOKE_KEEP:-0}" == "1" ]]; then
    say "Kept artifacts in $TMP (SMOKE_KEEP=1)"
  else
    rm -rf "$TMP"
  fi
  if (( FAIL_COUNT > 0 )); then
    echo
    echo "✗ Smoke test failed: $FAIL_COUNT of $PHASE_COUNT phases failed."
    exit 1
  fi
  exit "$exit_code"
}
trap cleanup EXIT INT TERM

say()  { printf "\033[36m▶\033[0m %s\n" "$*"; }
ok()   { printf "\033[32m✓\033[0m %s\n" "$*"; }
warn() { printf "\033[33m!\033[0m %s\n" "$*"; }
err()  { printf "\033[31m✗\033[0m %s\n" "$*"; }

# ──────────────────────────────────────────────────────────────────────────────
# Prereqs
# ──────────────────────────────────────────────────────────────────────────────

for cmd in php newman jq curl; do
  command -v "$cmd" >/dev/null || { err "missing required command: $cmd"; exit 1; }
done
[[ -f "$COLLECTION" ]] || { err "collection not found: $COLLECTION"; exit 1; }

# ──────────────────────────────────────────────────────────────────────────────
# 1. Isolated SQLite + .env.smoke
# ──────────────────────────────────────────────────────────────────────────────

say "Creating isolated SQLite at $DB"
: > "$DB"

APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"

cat > "$ENV_DOTENV" <<EOF
APP_NAME=stocs-auth-smoke
APP_ENV=smoke
APP_KEY=$APP_KEY
APP_DEBUG=true
APP_URL=$BASE
APP_FRONTEND_URL=http://localhost:3000

LOG_CHANNEL=stderr
LOG_LEVEL=debug

DB_CONNECTION=sqlite
DB_DATABASE=$DB

SESSION_DRIVER=array
CACHE_STORE=array
QUEUE_CONNECTION=sync
MAIL_MAILER=log
BCRYPT_ROUNDS=4

# These match the Postman env so the collection's seeded creds work.
SANCTUM_STATEFUL_DOMAINS=
EOF

export APP_ENV=smoke

# ──────────────────────────────────────────────────────────────────────────────
# 2. Migrate + seed
# ──────────────────────────────────────────────────────────────────────────────

say "Migrating + seeding"
( cd "$SRC" && php artisan migrate:fresh --seed --force --no-interaction >/dev/null )
ok "DB ready (seeded demo users)"

# ──────────────────────────────────────────────────────────────────────────────
# 3. Issue a service API key
# ──────────────────────────────────────────────────────────────────────────────

say "Issuing a Bids-scoped service key"
SERVICE_KEY="$(
  cd "$SRC" \
    && php artisan auth:issue-service-key smoke-test bids --no-interaction \
    | grep -E '^sk_[a-z0-9]+\.[A-Za-z0-9]+$' \
    | head -n1
)"
[[ -n "$SERVICE_KEY" ]] || { err "failed to capture service key from artisan output"; exit 1; }
ok "service key captured (${SERVICE_KEY:0:14}…)"

# ──────────────────────────────────────────────────────────────────────────────
# 4. Boot the API
# ──────────────────────────────────────────────────────────────────────────────

say "Starting php artisan serve on port $PORT"
(
  cd "$SRC"
  exec php artisan serve --host=127.0.0.1 --port="$PORT" >"$SERVE_LOG" 2>&1
) &
SERVE_PID=$!

# Wait for readiness (up to ~15s)
for i in $(seq 1 30); do
  if curl -fsS "$BASE/api/health" >/dev/null 2>&1; then
    ok "API ready at $BASE (pid $SERVE_PID)"
    break
  fi
  if ! kill -0 "$SERVE_PID" 2>/dev/null; then
    err "serve process died before becoming ready — see log:"
    cat "$SERVE_LOG"
    exit 1
  fi
  sleep 0.5
  if (( i == 30 )); then
    err "API never became ready"
    cat "$SERVE_LOG"
    exit 1
  fi
done

# ──────────────────────────────────────────────────────────────────────────────
# 5. Seed the Newman environment
# ──────────────────────────────────────────────────────────────────────────────
#
# We rebuild the env file rather than reusing the local one verbatim so the
# baseUrl + serviceKey + identifiers are pinned to this run.

jq \
  --arg base "$BASE" \
  --arg svc  "$SERVICE_KEY" \
  '
    .values |= map(
      if .key == "baseUrl"   then .value = $base
      elif .key == "serviceKey" then .value = $svc
      else . end)
  ' "$ENV_TEMPLATE" > "$ENV_RUNTIME"

# ──────────────────────────────────────────────────────────────────────────────
# Helpers — phase runner + log scraping
# ──────────────────────────────────────────────────────────────────────────────

# run_phase <label> <folder-or-request> [extra newman args...]
# Runs newman for a single folder or single request name and chains the
# environment forward (--export-environment writes back to $ENV_RUNTIME).
run_phase() {
  local label="$1"; shift
  local target="$1"; shift
  PHASE_COUNT=$((PHASE_COUNT + 1))
  say "[$PHASE_COUNT] $label"

  if newman run "$COLLECTION" \
       --folder "$target" \
       --environment "$ENV_RUNTIME" \
       --export-environment "$ENV_RUNTIME" \
       --reporters cli \
       --color on \
       "$@"; then
    ok "[$PHASE_COUNT] $label"
  else
    err "[$PHASE_COUNT] $label"
    FAIL_COUNT=$((FAIL_COUNT + 1))
  fi
}

# env_set <key> <value> — patch a single key in the running env file.
# Updates existing entries in place; appends if missing.
env_set() {
  local key="$1" value="$2"
  local tmp="$ENV_RUNTIME.tmp"
  jq --arg k "$key" --arg v "$value" \
    '.values |= (
       (map(if .key == $k then .value = $v else . end))
       | (if any(.[]; .key == $k)
            then .
            else . + [{key: $k, value: $v, type: "default", enabled: true}]
          end)
     )' \
    "$ENV_RUNTIME" > "$tmp"
  mv "$tmp" "$ENV_RUNTIME"
}

# scrape_otp_code — pulls the latest 6-digit OTP out of the mail log.
# The OtpCodeEmail template renders `<h1>{{ $code }}</h1>`.
scrape_otp_code() {
  grep -oE '<h1>[[:space:]]*[0-9]{6}[[:space:]]*</h1>' "$SERVE_LOG" \
    | tail -n1 \
    | grep -oE '[0-9]{6}'
}

# scrape_reset_token — pulls the latest reset-link token out of the mail log.
# The PasswordResetEmail template renders ?token=<token>&email=...
scrape_reset_token() {
  grep -oE 'token=[A-Za-z0-9]+' "$SERVE_LOG" \
    | tail -n1 \
    | cut -d= -f2
}

# ──────────────────────────────────────────────────────────────────────────────
# 6. The flow
# ──────────────────────────────────────────────────────────────────────────────

# Phase A: sanity + admin token. The seeded admin@stocs.test logs in via the
# same B2B endpoint; the request-level capture script writes adminToken.
run_phase "Health"          "Health"
run_phase "Login Admin"     "Login Admin"

# Phase B: gated B2B onboarding. Register creates a pending user, captures the
# new user's token+uuid. We then approve via admin and re-login.
run_phase "Register B2B"    "Register B2B"
run_phase "Admin: List Users (pending)" "List Users"
run_phase "Admin: Get User" "Get User"
run_phase "Admin: Update User" "Update User"
run_phase "Admin: Approve B2B" "Approve"

# After approval the user can log in. Override testEmail to the just-registered
# user; reset to default after this phase so the seeded wholesaler is used for
# subsequent flows.
env_set testEmail    "new-wholesaler@stocs.test"
env_set testPassword "TestPassword1234"
run_phase "Login B2B (newly approved)" "Login B2B"
env_set testEmail    "wholesale@stocs.test"
env_set testPassword "testing1!"

# Phase C: authenticated /me endpoints. We log back in as the seeded wholesaler
# so the password-reset flow further down can hit a stable account.
run_phase "Login B2B (seeded wholesaler)" "Login B2B"
run_phase "Me: Get Me"           "Get Me"
run_phase "Me: Update Profile"   "Update Profile"
run_phase "Me: Update Marketing" "Update Marketing"

# Phase D: OTP. Request → scrape code from log → verify.
run_phase "OTP: Request" "Request OTP"
OTP_CODE="$(scrape_otp_code || true)"
if [[ -z "$OTP_CODE" ]]; then
  err "could not scrape OTP code from serve log — last 40 log lines:"
  tail -n40 "$SERVE_LOG"
  FAIL_COUNT=$((FAIL_COUNT + 1))
else
  ok "scraped OTP code $OTP_CODE"
  env_set otpCode "$OTP_CODE"
  run_phase "OTP: Verify" "Verify OTP"
fi

# After Verify OTP the env now holds a Bids-scoped userToken. Validate it
# against the service endpoint before we move on, while it's still fresh.
run_phase "Service: Validate Bids token" "Validate Token"

# Phase E: password reset. Hit Forgot Password as the seeded wholesaler, scrape
# the token, reset, then log in with the NEW password to prove revocation.
env_set testEmail "wholesale@stocs.test"
run_phase "Password: Forgot" "Forgot Password"
RESET_TOKEN="$(scrape_reset_token || true)"
if [[ -z "$RESET_TOKEN" ]]; then
  err "could not scrape password reset token from log — last 40 log lines:"
  tail -n40 "$SERVE_LOG"
  FAIL_COUNT=$((FAIL_COUNT + 1))
else
  ok "scraped reset token ${RESET_TOKEN:0:8}…"
  env_set resetToken "$RESET_TOKEN"
  run_phase "Password: Reset" "Reset Password"
  env_set testPassword "NewPassword1234"
  run_phase "Login B2B (new password)" "Login B2B"
fi

# Phase F: service-to-service lookups (use the B2B token we just got).
run_phase "Service: Validate B2B token" "Validate Token"
env_set userUuid "00000000-0000-4000-8000-0000000000b0"  # seeded wholesaler
run_phase "Service: Get User by UUID"   "Get User by UUID"
run_phase "Service: Get User by Email"  "Get User by Email"

# Phase G: destructive admin actions on the registered user. We re-aim
# userUuid at the originally-registered new wholesaler before suspending.
NEW_USER_UUID="$(jq -r '.values[] | select(.key=="userUuid") | .value' "$ENV_TEMPLATE" 2>/dev/null || true)"
# That was actually the seed value — look up the live one by email instead.
NEW_USER_UUID="$(
  curl -fsS -H "X-Service-Key: $SERVICE_KEY" \
    "$BASE/api/v1/service/users/by-email/new-wholesaler@stocs.test" \
    | jq -r '.data.uuid // .uuid'
)"
env_set userUuid "$NEW_USER_UUID"
run_phase "Admin: Reject"  "Reject"
run_phase "Admin: Suspend" "Suspend"
run_phase "Admin: Create Admin" "Create Admin"
run_phase "Admin: Audit Logs"   "Audit Logs"

# Phase H: dev-only — mint a batch of Bids users (uses the service key).
run_phase "Service: Mint Test Users" "Mint Test Users (dev-only)"

# Phase I: logout the current B2B session.
run_phase "Me: Logout" "Logout"

# ──────────────────────────────────────────────────────────────────────────────
# Summary
# ──────────────────────────────────────────────────────────────────────────────

echo
if (( FAIL_COUNT == 0 )); then
  ok "All $PHASE_COUNT phases passed."
fi
