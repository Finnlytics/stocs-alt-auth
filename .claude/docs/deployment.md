# Deployment

How stocs-auth reaches staging and production on DigitalOcean App Platform.

One app per environment: an `api` service, a `queue` worker, and a `migrate` PRE_DEPLOY job.
Kept deliberately in step with stocs-bids' setup, since the two repos drifting apart is how
this one ended up with no CI at all while bids had a gate.

## Reading before writing

### Always dump the live spec before changing one

`doctl apps spec get <APP_ID>` is the source of truth for what the app actually runs. Every
spec detail reasoned about rather than read turned out wrong somewhere during the deployment
work; every detail read off the running app was right.

This also settles questions that are otherwise guesswork. The live app uses `${APP_URL}` and
`${stocs-main.DATABASE_URL}`, which is how we know DigitalOcean resolves both the
self-referential and the database binding forms.

### `doctl apps update --spec` is a full replace, not a patch

The spec becomes the entire desired state, so anything present on the app but absent from
the spec is **deleted**, env vars included. Config set only in the DO dashboard survives
until the next deploy and then vanishes, and the deploy that removes it looks unrelated.

## Environment configuration

### Config is split in two halves, merged at deploy time

`.do/render-spec.sh` merges `.do/app*.yaml` (structure and DB bindings),
`.env.{production,staging}.example` (the non-secret half, committed) and GitHub Secrets (the
secret half, named in `.do/secrets.list`), then pipes the result to `doctl`.

Only three secrets: `APP_KEY`, `MAIL_PASSWORD`, and `DB_PASSWORD` is **not** among them
because DB values come from `${stocs-main.*}` bindings and never pass through the repo or
GitHub. The script is byte-identical to bids' copy so the two cannot diverge in how they
render.

### The templates are deliberately minimal

The running app sets 29 env vars and relies on framework defaults for the rest, where bids
sets 80. The templates match that rather than being exhaustive: because a spec apply replaces
everything, each key added here is config added to a working service rather than a default
inherited.

### Templates are parsed, never sourced

Sourcing expands `${APP_URL}` and `${stocs-main.HOSTNAME}` into empty strings, and the app
then boots with no URL and no database. They are DigitalOcean bindings that must reach DO as
literal text.

### `.env.production` is gitignored; the committed file is `.env.production.example`

Laravel loads `.env.{APP_ENV}` at boot, so that filename means "the real file, with secrets".
`.env.staging` is ignored for the same reason.

## Components

### The queue worker is not optional, and its failure is the quietest in the platform

All five mailables implement `ShouldQueue`, including `OtpCodeEmail`. Consumer login is
OTP-only, so with no worker **no code is ever delivered and nobody can log in to the consumer
site**, while auth itself returns 200s and looks perfectly healthy.

It correctly takes no `--queue` flag: auth dispatches nothing to a named queue. (bids does use
a named `sync` queue, which is why its worker needs `--queue=default,sync`.)

### Migrations run in a PRE_DEPLOY job, not the entrypoint

The entrypoint would run them once per instance, and Laravel has no migration lock. App
Platform bills jobs for run time rather than monthly, so this costs pennies; `doctl apps
propose` quotes it as always-on, making its total an upper bound.

### Operator admins are never seeded on deploy

`AdminUsersSeeder` reapplies the password from env on every run, so seeding on deploy would
silently revert one an operator changed in-app. Seed once after a first deploy:

```bash
php artisan db:seed --class=AdminUsersSeeder
```

Never a bare `db:seed`: `DatabaseSeeder` also calls `DemoUsersSeeder`, which must not run in
production. `ADMIN_1_*` must be in the app environment at that moment; entries with a missing
email or password are skipped, so an unset slot is inert rather than broken.

## `APP_ENV` has one real consequence here

Setting it to anything other than `production` enables the dev-only test-user mint endpoint,
because `MintTestUsersRequest::authorize()` returns `! app()->isProduction()`.

That matters: `POST /api/v1/service/test-users/mint` is what bids' `bids:simulate-http` load
harness uses to mint tokens, so with `APP_ENV=production` on staging the HTTP load test cannot
run at all. Nothing else in auth branches on the environment name.

## Database

### The app connects as `doadmin`, the cluster superuser

It can read and write every database on the shared cluster, including bids' and b2b's, which
holds regardless of which apps authenticate through auth. For the service that owns identity
that inverts least-privilege, and it means compromising auth reaches data unrelated to
identity.

A dedicated `auth_2026_07` user exists on the cluster and is unused. Switching means first
confirming its privileges on the target database, because getting it wrong means auth cannot
boot, and that takes consumer login down. Prove it on staging first.

### Managed MySQL users are cluster-wide

DigitalOcean does not scope a managed MySQL user to one database, so environment isolation
rests entirely on `DB_DATABASE` being correct, with no permission boundary behind it.

### A new app must be in the cluster's trusted sources

Attaching the cluster via the spec's `databases:` block does this automatically. If it does
not happen the app cannot reach the database, and the symptom is indistinguishable from a
binding that failed to resolve.

## GitHub Actions

### `workflow_dispatch` requires the workflow file on the default branch

A workflow that exists only on a feature branch does not appear in the Actions UI and cannot
be dispatched. Once on the default branch it can run against any ref, using that ref's
version of the file.

## Scope note

stocs-b2b does **not** authenticate through this service today; it still has its own auth,
and unifying them is a future intention. So an auth outage currently takes down consumer
login on Bids, not every STOCS site. The B2B endpoints and `b2b` platform records are
built-and-waiting rather than in use.
