<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use App\Policies\BranchPolicy;
use App\Policies\CostCenterPolicy;
use App\Policies\UserPolicy;
use App\Support\Audit\AuditContext;
use App\Support\Rbac\Rbac;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Origem 'queue' no audit trail enquanto um job é processado.
        Queue::before(fn () => AuditContext::$inQueueJob = true);
        Queue::after(fn () => AuditContext::$inQueueJob = false);
        Queue::failing(fn () => AuditContext::$inQueueJob = false);

        // 120 req/min por usuário (ou IP quando anônimo).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Login: 5/min por (e-mail + IP) e 20/min por IP (spec D.1). Conta toda tentativa, inclusive as bem-sucedidas.
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');
            $email = is_string($email) ? mb_strtolower(trim($email)) : '';

            return [
                Limit::perMinute(5)->by('login:email-ip:'.$email.'|'.$request->ip()),
                Limit::perMinute(20)->by('login:ip:'.$request->ip()),
            ];
        });

        // Política de senha (spec D.2): mín. 10 caracteres, maiúscula, minúscula e número.
        Password::defaults(fn () => Password::min(10)->mixedCase()->numbers());

        // RBAC (spec E): Gates por permissão + auth.session; policies dos models existentes
        // (cada cadastro F1-11..16 registra a sua aqui).
        Rbac::register();
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(CostCenter::class, CostCenterPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);

        // Token de usuário inativo ou soft-deleted (tokenable null) não autentica.
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid) => $isValid && $token->tokenable instanceof User && $token->tokenable->is_active,
        );
    }
}
