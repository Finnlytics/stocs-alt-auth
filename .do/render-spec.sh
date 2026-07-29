#!/usr/bin/env bash
#
# Render a complete App Platform spec from three inputs:
#
#   1. a base spec from .do/            structure, components, DB bindings
#   2. an env template                  the non-secret half, committed
#   3. the process environment          the secret half, named in .do/secrets.list
#
# Writes the result to stdout. Nothing is written to disk, so piping straight into
# `doctl apps update --spec -` keeps secret values out of the filesystem and out of any
# CI artifact.
#
# Usage:
#   .do/render-spec.sh <base-spec> <env-template> <image-tag>
#
# Inspect what would be applied (secret VALUES are redacted in this mode, so it is safe
# to paste the output):
#   .do/render-spec.sh .do/app.yaml .env.production.example latest --redact
#
# Apply for real:
#   .do/render-spec.sh .do/app.yaml .env.production.example "$TAG" \
#     | doctl apps update <APP_ID> --spec - --wait
#
# Create for the first time:
#   .do/render-spec.sh .do/app.yaml .env.production.example "$TAG" \
#     | doctl apps create --spec - --wait
#
# WHY A SCRIPT AND NOT INLINE WORKFLOW YAML
#
# So the same code runs in CI and by hand. Rendering logic that only exists inside a
# workflow can only be tested by pushing commits, and a local reimplementation of it
# would drift from the real one.

set -euo pipefail

BASE_SPEC="${1:?usage: render-spec.sh <base-spec> <env-template> <image-tag> [--redact]}"
ENV_TEMPLATE="${2:?missing env template}"
IMAGE_TAG="${3:?missing image tag}"
REDACT="${4:-}"

SECRETS_LIST="$(dirname "$0")/secrets.list"

for f in "$BASE_SPEC" "$ENV_TEMPLATE" "$SECRETS_LIST"; do
    [ -f "$f" ] || { echo "render-spec: no such file: $f" >&2; exit 1; }
done

command -v yq >/dev/null || { echo "render-spec: yq is required (v4)" >&2; exit 1; }

# --- 1. the secret half -----------------------------------------------------------
#
# Read the declared names first and fail on any that are unset, before doing any work.
# Refusing here is the whole point: an app deployed with an empty APP_KEY or
# STRIPE_SECRET fails in ways that take a while to attribute back to a missing secret.
#
# An empty value is treated as missing EXCEPT where emptiness is meaningful. bids'
# PREVIEW_PASSWORD is the case that matters: empty is how the preview gate is switched
# off, which is the production go-live switch, so "" has to be distinguishable from
# "nobody set this".
# A trailing ? marks a secret as OPTIONAL: included when set, skipped when not, never
# fatal. That exists for a credential a feature needs only while the feature is switched
# on, so an environment running with the feature off does not have to hold a value it
# cannot use.
#
# Used sparingly and always paired with its feature flag being false in the template.
# Silently skipping a credential is the failure mode this whole check exists to prevent,
# so every skip is reported on stderr rather than passing quietly.
missing=()
secret_keys=()
optional_keys=()
while read -r key; do
    key="${key%%#*}"; key="${key//[[:space:]]/}"
    [ -z "$key" ] && continue

    optional=false
    if [[ "$key" == *\? ]]; then
        optional=true
        key="${key%\?}"
        optional_keys+=("$key")
    fi

    if [ "$key" = "PREVIEW_PASSWORD" ]; then
        # Declared-but-empty is legitimate here: empty is how the preview gate is switched
        # off, which is the production go-live switch, so it has to be distinguishable
        # from nobody having set it. Only an entirely unset var is a problem.
        [ -z "${!key+x}" ] && missing+=("$key")
        secret_keys+=("$key")
    elif [ -z "${!key:-}" ]; then
        if [ "$optional" = true ]; then
            echo "render-spec: $key is unset and declared optional, so it is not being set on the app." >&2
            echo "             The feature relying on it must be off in $(basename "$ENV_TEMPLATE")." >&2
        else
            missing+=("$key")
            secret_keys+=("$key")
        fi
    else
        secret_keys+=("$key")
    fi
done < "$SECRETS_LIST"

if [ ${#missing[@]} -gt 0 ]; then
    echo "render-spec: these secrets are declared in $SECRETS_LIST but not set in the environment:" >&2
    for k in "${missing[@]}"; do echo "  - $k" >&2; done
    echo >&2
    echo "In CI, add them to the GitHub Environment. Locally, export them before running." >&2
    exit 1
fi

# An optional secret that is unset while the feature depending on it is switched ON is
# worse than a missing required secret, because it deploys cleanly and then fails at the
# point of use: a queued job throwing on every send, rather than anything visible at
# deploy time.
#
# The pairs are spelled out rather than derived, because the names are not a mechanical
# transform of each other (WHATSAPP_ACCESS_TOKEN pairs with
# WHATSAPP_NOTIFICATIONS_ENABLED, not WHATSAPP_ACCESS_TOKEN_ENABLED).
flag_for_secret() {
    case "$1" in
        WHATSAPP_ACCESS_TOKEN)                     echo WHATSAPP_NOTIFICATIONS_ENABLED ;;
        WEB_PUSH_PUBLIC_KEY|WEB_PUSH_PRIVATE_KEY)  echo WEB_PUSH_ENABLED ;;
        *)                                         echo "" ;;
    esac
}

for key in "${optional_keys[@]:-}"; do
    [ -z "$key" ] && continue

    flag="$(flag_for_secret "$key")"

    # An optional secret with no registered flag has optionality WITHOUT the guard below,
    # which is the hole the guard exists to close. Refusing rather than warning, because a
    # warning in a CI log is not read: it would let the next feature reintroduce exactly
    # the failure this mechanism was added to prevent.
    #
    # To make a new secret optional, add its flag to flag_for_secret above. If the feature
    # has no flag, it cannot be optional: there would be no way to tell "deliberately off"
    # from "credential missing", so declare it required instead.
    if [ -z "$flag" ]; then
        echo "render-spec: $key is marked optional in $SECRETS_LIST but has no feature flag" >&2
        echo "             registered in flag_for_secret(). Optional-without-a-guard means an" >&2
        echo "             unset value would deploy cleanly and fail at the point of use." >&2
        echo "             Add the pair to flag_for_secret(), or declare $key required." >&2
        exit 1
    fi

    [ -n "${!key:-}" ] && continue

    if grep -qE "^${flag}=true$" "$ENV_TEMPLATE"; then
        echo "render-spec: $key is unset, but $ENV_TEMPLATE has ${flag}=true." >&2
        echo "             That combination deploys cleanly and then fails when the feature is" >&2
        echo "             used. Either set $key, or set ${flag}=false." >&2
        exit 1
    fi
done

# --- 2. the non-secret half -------------------------------------------------------
#
# Parsed rather than sourced, ON PURPOSE. Sourcing would let the shell expand values,
# and several are meant to reach DigitalOcean as literal text: ${APP_URL} and
# ${stocs-main.HOSTNAME} are DO bindings that DO resolves, not shell variables. Sourcing
# turns them into empty strings and the app then boots with no URL and no database.
#
# Quotes around a whole value are stripped, since they are dotenv syntax rather than part
# of the value. Inline comments are NOT stripped: a # can legitimately appear inside a
# value, and no current value needs a trailing comment.
spec="$(cat "$BASE_SPEC")"

while IFS= read -r line; do
    case "$line" in
        ''|'#'*) continue ;;
    esac
    [[ "$line" == *=* ]] || continue

    key="${line%%=*}"
    value="${line#*=}"
    [[ "$key" =~ ^[A-Z][A-Z0-9_]*$ ]] || continue

    # strip one layer of surrounding quotes
    if [[ "$value" == \"*\" && ${#value} -ge 2 ]]; then
        value="${value:1:${#value}-2}"
    fi

    # A key already present in the base spec wins: those are the DB bindings, which are
    # deliberately not driven by the template.
    if yq -e ".envs[]? | select(.key == \"$key\")" <<<"$spec" >/dev/null 2>&1; then
        echo "render-spec: skipping $key, already set in $BASE_SPEC" >&2
        continue
    fi

    spec="$(KEY="$key" VALUE="$value" yq '
        .envs += [{"key": strenv(KEY), "scope": "RUN_TIME", "value": strenv(VALUE)}]
    ' <<<"$spec")"
done < "$ENV_TEMPLATE"

# --- 3. the secrets ---------------------------------------------------------------
for key in "${secret_keys[@]}"; do
    value="${!key:-}"
    [ "$REDACT" = "--redact" ] && value="<redacted>"

    spec="$(KEY="$key" VALUE="$value" yq '
        .envs += [{"key": strenv(KEY), "scope": "RUN_TIME", "type": "SECRET", "value": strenv(VALUE)}]
    ' <<<"$spec")"
done

# --- 4. the image tag -------------------------------------------------------------
#
# __IMAGE_TAG__ rather than ${IMAGE_TAG} in the spec files, so it cannot be mistaken for
# a DigitalOcean binding by anyone reading them.
if [[ "$spec" != *__IMAGE_TAG__* ]]; then
    echo "render-spec: $BASE_SPEC contains no __IMAGE_TAG__ placeholder" >&2
    exit 1
fi
spec="${spec//__IMAGE_TAG__/$IMAGE_TAG}"

# --- 5. sanity checks before anything is applied ----------------------------------
if [[ "$spec" == *__SET_ME__* ]]; then
    echo "render-spec: rendered spec still contains a __SET_ME__ placeholder." >&2
    echo "Fill it in $ENV_TEMPLATE before deploying, or the literal string reaches live config." >&2
    exit 1
fi

yq -e '.name and .services and (.envs | length > 0)' <<<"$spec" >/dev/null || {
    echo "render-spec: rendered spec failed a basic shape check" >&2
    exit 1
}

echo "$spec"
