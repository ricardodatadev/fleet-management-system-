<?php

use App\Console\Commands\SchedulerHeartbeat;
use Illuminate\Support\Facades\Schedule;

/*
 * Agendamentos (F1-19). Executados pelo serviço `scheduler` do compose (`php artisan schedule:work`); o
 * worker roda só o Horizon. Todas as tarefas com withoutOverlapping().
 */

// Batimento do scheduler: o healthcheck do serviço falha se o arquivo ficar mais de 150 s sem atualizar.
Schedule::command(SchedulerHeartbeat::class)->everyMinute()->withoutOverlapping();

// Tokens Sanctum expirados há mais de 24 h são apagados (pendência da F1-09).
Schedule::command('sanctum:prune-expired --hours=24')->daily()->withoutOverlapping();
