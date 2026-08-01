<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MfaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API V1 Routes
|--------------------------------------------------------------------------
|
| Routes for API version 1.
|
*/

// Public routes with auth rate limiter (5/min - brute force protection)
Route::middleware('throttle:auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->name('api.v1.register');
    Route::post('login', [AuthController::class, 'login'])->name('api.v1.login');

    // MFA & 3FA verification & setup
    Route::post('mfa/verify', [MfaController::class, 'verify'])->name('api.v1.mfa.verify');
    Route::post('mfa/email/verify', [MfaController::class, 'verifyEmailOtp'])->name('api.v1.mfa.email.verify');
    Route::get('mfa/setup', [MfaController::class, 'showSetup'])->name('api.v1.mfa.setup');
    Route::post('mfa/setup/confirm', [MfaController::class, 'confirmSetup'])->name('api.v1.mfa.setup.confirm');
});

// Protected routes for active authenticated users (120/min)
Route::middleware(['auth:sanctum', 'active', 'throttle:authenticated'])->group(function (): void {
    Route::post('logout', [AuthController::class, 'logout'])->name('api.v1.logout');
    Route::get('me', [AuthController::class, 'me'])->name('api.v1.me');

    // Email verification
    Route::post('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')
        ->name('verification.verify');
    Route::post('email/resend', [AuthController::class, 'resendVerificationEmail'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

// Admin-only protected routes
Route::middleware(['auth:sanctum', 'active', 'role:Administrador', 'throttle:authenticated'])->group(function (): void {
    Route::get('admin/historical-data', [AuditController::class, 'getHistoricalData'])
        ->name('api.v1.admin.historical_data');
});

// Password reset routes (public with rate limiting)
Route::middleware('throttle:6,1')->group(function (): void {
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
        ->name('password.email');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])
        ->name('password.reset');
});
