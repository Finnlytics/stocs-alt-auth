<?php

namespace App\Providers;

use App\Contracts\OtpNotifier;
use App\Otp\FileOtpNotifier;
use App\Otp\MailOtpNotifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OtpNotifier::class, function () {
            return config('otp.notifier') === 'file'
                ? new FileOtpNotifier
                : new MailOtpNotifier;
        });
    }

    public function boot(): void
    {
        // Auth endpoints: 5 requests per minute per IP
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // OTP requests: coarse per-identifier network backstop only. The precise
        // resend policy (initial send + 3 free resends, then exponential backoff
        // to 1/day) lives in OtpService's DB-backed backoff — these caps just sit
        // above it so they never block a legitimately-allowed resend (the 3 free
        // resends can all land inside a minute) while still stopping raw floods.
        RateLimiter::for('otp', function (Request $request) {
            $key = $request->input('identifier') ?: $request->ip();

            return [
                Limit::perHour(8)->by('otp:hour:'.$key),
                Limit::perMinute(5)->by('otp:minute:'.$key),
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
