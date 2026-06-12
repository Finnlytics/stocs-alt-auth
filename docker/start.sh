#!/usr/bin/env bash
# Web entrypoint: prime caches with runtime env, then run Apache in the foreground.
set -e

php artisan config:cache
php artisan route:cache

exec apache2-foreground
