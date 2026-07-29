# Testing

Suite runs on in-memory SQLite, configured in `phpunit.xml`. No database service needed, and
no Node or Vite steps: the only view calling `@vite` is the unrouted `welcome.blade.php`, so
no asset manifest is required.

```bash
php artisan test
```

## Environment traps

### The suite needs no `.env`, and that is deliberate

`phpunit.xml` supplies a throwaway `APP_KEY`, so `vendor/bin/phpunit` passes on a fresh clone
with no `.env` at all. Before that key was added, the suite passed locally only because a
developer `.env` happened to exist, and would have died with `MissingAppKeyException` on the
first CI run.

Note `php artisan test` still emits warnings when `.env` is absent, while `vendor/bin/phpunit`
runs clean. CI writes one from `.env.example` anyway, because a `route:list` boot check that
runs without the app's real config proves very little.

### `phpunit.xml` `<env>` does NOT override an already-set environment variable

PHPUnit only applies an `<env>` value when the variable is absent, unless `force="true"`. So
exporting a variable in your shell silently replaces the test config for the rest of that
session.

Easy to hit when testing `.do/render-spec.sh`, which needs fake secrets exported. Prefer
inline `env VAR=x cmd` over `export`, or unset afterwards:

```bash
env -u APP_KEY -u MAIL_PASSWORD php artisan test
```

### A local `vendor/` can be stale against `composer.lock`

`composer install` is not automatic, so the local tree can sit several packages behind the
lock while CI and the Docker image install fresh from it, meaning the local suite exercises a
different dependency set than production. Run `composer install` before trusting local
behaviour, especially when a class "does not exist".

## Test design

### Verify a new guard actually fails without the fix

Checks written during the deployment work passed vacuously on the first attempt: one regex
matched the comment describing it. Revert the fix, confirm the test fails, restore it. A guard
that cannot fail reads as coverage while providing none.

## CI

The `validate` job gates deploys and runs `composer validate --strict`, `composer audit
--locked --no-dev`, `pint --test`, a `route:list` boot check, and the suite. It also validates
the committed env templates; see [deployment.md](deployment.md).

### Blocking dependency audits mean a third party can block a deploy

An advisory published against a transitive dependency fails the gate with no change on our
side. This repo went 22 advisories across 9 packages behind while bids was kept patched, all
transitive under loose constraints, and a targeted `composer update --with-dependencies`
cleared them without touching `composer.json`.

Two dev-only phpunit advisories remain and are not gating, since CI audits with `--no-dev`.

### larastan is not wired up yet

stocs-b2b's CI runs phpstan; neither repo here has it installed. Adding `larastan/larastan`
plus a `phpstan.neon` to both is deliberate future work, not an oversight.
