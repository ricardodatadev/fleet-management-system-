<?php

namespace App\Providers;

use App\Support\Audit\AuditContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        // 120 req/min por usuário (ou IP quando anônimo). O limiter de login entra na F1-09.
        // Origem 'queue' no audit trail enquanto um job é processado.
        Queue::before(fn () => AuditContext::$inQueueJob = true);
        Queue::after(fn () => AuditContext::$inQueueJob = false);
        Queue::failing(fn () => AuditContext::$inQueueJob = false);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }
}
