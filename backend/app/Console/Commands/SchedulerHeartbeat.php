<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Grava o batimento do scheduler (storage/framework/schedule-heartbeat). Agendado a cada minuto; o
 * healthcheck do serviço `scheduler` olha a idade do arquivo.
 */
class SchedulerHeartbeat extends Command
{
    protected $signature = 'scheduler:heartbeat';

    protected $description = 'Grava o batimento do scheduler (usado pelo healthcheck do serviço scheduler)';

    public static function path(): string
    {
        return storage_path('framework/schedule-heartbeat');
    }

    public function handle(): int
    {
        file_put_contents(self::path(), now()->toIso8601String().PHP_EOL);

        return self::SUCCESS;
    }
}
