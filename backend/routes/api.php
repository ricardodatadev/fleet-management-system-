<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MetaController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 aplicado em bootstrap/app.php.
// Health fora do throttle: o limiter usa o cache (Redis) e o health precisa responder 503 quando ele cai.
Route::get('health', HealthController::class)->name('api.health')->withoutMiddleware('throttle:api');
Route::get('meta/enums', [MetaController::class, 'enums'])->name('api.meta.enums');

// Auth (D.2). Login público com throttle próprio (5/min por e-mail+IP e 20/min por IP).
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('api.auth.login');
Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::get('auth/me', [AuthController::class, 'me'])->name('api.auth.me');
    Route::put('auth/password', [AuthController::class, 'changePassword'])->name('api.auth.password');
});
