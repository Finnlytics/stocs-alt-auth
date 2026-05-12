<?php

use App\Http\Controllers\Admin\AdminAuditLogController;
use App\Http\Controllers\Admin\AdminUsersController;
use App\Http\Controllers\Auth\B2bLoginController;
use App\Http\Controllers\Auth\B2bRegisterController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\Service\TestUsersController;
use App\Http\Controllers\Service\TokenValidationController;
use App\Http\Controllers\Service\UserLookupController;
use Illuminate\Support\Facades\Route;

// Health check
Route::get('/health', fn () => response()->json(['status' => 'ok']));

// OpenAPI spec — import into Postman / generate clients
Route::get('/docs/openapi.yaml', [DocsController::class, 'openapi']);

// ──────────────────────────────────────────────
// Public Auth Endpoints (rate limited)
// ──────────────────────────────────────────────

Route::prefix('v1/auth')->middleware('throttle:auth')->group(function () {
    // B2B password auth
    Route::post('/register/b2b', B2bRegisterController::class);
    Route::post('/login/b2b', B2bLoginController::class);

    // Bids OTP auth
    Route::post('/otp/request', [OtpController::class, 'request'])->middleware('throttle:otp');
    Route::post('/otp/verify', [OtpController::class, 'verify']);

    // Password reset
    Route::post('/password/forgot', [PasswordResetController::class, 'forgot']);
    Route::post('/password/reset', [PasswordResetController::class, 'reset']);
});

// ──────────────────────────────────────────────
// Authenticated User Endpoints
// ──────────────────────────────────────────────

Route::prefix('v1/auth')->middleware('auth:sanctum')->group(function () {
    Route::get('/me', [MeController::class, 'show']);
    Route::put('/me', [MeController::class, 'update']);
    Route::put('/me/password', [MeController::class, 'updatePassword']);
    Route::put('/me/marketing', [MeController::class, 'updateMarketing']);
    Route::delete('/me', [MeController::class, 'destroy']);
    Route::post('/logout', [MeController::class, 'logout']);
    Route::post('/logout/all', [MeController::class, 'logoutAll']);
});

// ──────────────────────────────────────────────
// Admin Endpoints (Sanctum + admin role)
// ──────────────────────────────────────────────

Route::prefix('v1/admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/users', [AdminUsersController::class, 'index']);
    Route::get('/users/{uuid}', [AdminUsersController::class, 'show']);
    Route::put('/users/{uuid}', [AdminUsersController::class, 'update']);
    Route::post('/users/{uuid}/approve', [AdminUsersController::class, 'approve']);
    Route::post('/users/{uuid}/reject', [AdminUsersController::class, 'reject']);
    Route::post('/users/{uuid}/suspend', [AdminUsersController::class, 'suspend']);
    Route::post('/users', [AdminUsersController::class, 'store']);
    Route::get('/audit-logs', [AdminAuditLogController::class, 'index']);
});

// ──────────────────────────────────────────────
// Service-to-Service Endpoints (API key auth)
// ──────────────────────────────────────────────

Route::prefix('v1/service')->middleware('service-key')->group(function () {
    Route::post('/validate-token', [TokenValidationController::class, 'validate']);
    Route::get('/users/{uuid}', [UserLookupController::class, 'showByUuid']);
    Route::get('/users/by-email/{email}', [UserLookupController::class, 'showByEmail']);

    // Dev-only: mint N Bids-scoped tokens for load testing. TestUsersController
    // guards against APP_ENV=production; the service-key check is belt-and-braces.
    Route::post('/test-users/mint', [TestUsersController::class, 'mint']);
});
