<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CostCenterController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EquipmentFamilyController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1 aplicado em bootstrap/app.php.
// Convenção (F1-10): toda rota fora da allowlist [health, auth/login] declara auth:sanctum + can:<permissão>
// (Gates de config/rbac.php ou auth.session = qualquer autenticado). Coberto por tests/Feature/Rbac.

// Health fora do throttle: o limiter usa o cache (Redis) e o health precisa responder 503 quando ele cai.
Route::get('health', HealthController::class)->name('api.health')->withoutMiddleware('throttle:api');

// Auth (D.2). Login público com throttle próprio (5/min por e-mail+IP e 20/min por IP).
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('api.auth.login');

Route::middleware(['auth:sanctum', 'can:auth.session'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::get('auth/me', [AuthController::class, 'me'])->name('api.auth.me');
    Route::put('auth/password', [AuthController::class, 'changePassword'])->name('api.auth.password');
    Route::get('meta/enums', [MetaController::class, 'enums'])->name('api.meta.enums');
});

// Cadastros (D.2). Leitura com {recurso}.view; escrita, exclusão e restore com {recurso}.manage.
// Autorização por instância (view/update/delete/restore) no controller, depois do binding.
Route::middleware('auth:sanctum')->group(function () {
    foreach ([
        'branches' => [BranchController::class, 'branch', 'branches'],
        'cost-centers' => [CostCenterController::class, 'cost_center', 'cost_centers'],
        'equipment-families' => [EquipmentFamilyController::class, 'equipment_family', 'equipment_families'],
        'employees' => [EmployeeController::class, 'employee', 'employees'],
        'users' => [UserController::class, 'user', 'users'],
    ] as $uri => [$controller, $param, $permission]) {
        $name = 'api.'.str_replace('-', '_', $uri);
        Route::get($uri, [$controller, 'index'])->middleware("can:{$permission}.view")->name("{$name}.index");
        Route::post($uri, [$controller, 'store'])->middleware("can:{$permission}.manage")->name("{$name}.store");
        Route::get("{$uri}/{{$param}}", [$controller, 'show'])->middleware("can:{$permission}.view")->name("{$name}.show");
        Route::match(['PUT', 'PATCH'], "{$uri}/{{$param}}", [$controller, 'update'])->middleware("can:{$permission}.manage")->name("{$name}.update");
        Route::delete("{$uri}/{{$param}}", [$controller, 'destroy'])->middleware("can:{$permission}.manage")->name("{$name}.destroy");
        Route::post("{$uri}/{{$param}}/restore", [$controller, 'restore'])->middleware("can:{$permission}.manage")->withTrashed()->name("{$name}.restore");
    }

    // Auditoria (F.1): somente leitura. Sem POST/PUT/PATCH/DELETE (→ 405), nem para admin.
    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('can:audit.view')->name('api.audit_logs.index');
    Route::get('audit-logs/{audit_log}', [AuditLogController::class, 'show'])->middleware('can:audit.view')->name('api.audit_logs.show');
});
