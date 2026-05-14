<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Auth endpoints: 5 requests per minute per IP
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // OTP requests: business rule — max 5 per hour per identifier (also falls back to IP)
        RateLimiter::for('otp', function (Request $request) {
            $key = $request->input('identifier') ?: $request->ip();

            return [
                Limit::perHour(5)->by('otp:hour:'.$key),
                Limit::perMinute(3)->by('otp:minute:'.$key),
            ];
        });

        // Service endpoints: default 100 per minute per API key
        RateLimiter::for('service', function (Request $request) {
            return Limit::perMinute(100)->by($request->header('X-Service-Key', $request->ip()));
        });

        // Service token validation: hotter path — 600/min per API key
        RateLimiter::for('service-validate', function (Request $request) {
            return Limit::perMinute(600)->by($request->header('X-Service-Key', $request->ip()));
        });

        // Service test-user mint: dev-only, tighter cap to avoid DB exhaustion
        RateLimiter::for('service-mint', function (Request $request) {
            return Limit::perMinute(10)->by($request->header('X-Service-Key', $request->ip()));
        });
    }
}
