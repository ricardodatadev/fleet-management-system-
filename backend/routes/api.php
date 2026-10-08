<?php

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MetaController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 aplicado em bootstrap/app.php.
// Health fora do throttle: o limiter usa o cache (Redis) e o health precisa responder 503 quando ele cai.
Route::get('health', HealthController::class)->name('api.health')->withoutMiddleware('throttle:api');
Route::get('meta/enums', [MetaController::class, 'enums'])->name('api.meta.enums');
