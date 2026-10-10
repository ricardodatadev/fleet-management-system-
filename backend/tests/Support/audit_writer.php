<?php

/**
 * Processo filho do teste de concorrência: grava N eventos de auditoria (cada um em sua transação).
 * Uso: php tests/Support/audit_writer.php <worker> <n>   (env de teste forçado pelo processo pai)
 */

use App\Enums\AuditAction;
use App\Support\Audit\AuditService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabaseGuard;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

TestDatabaseGuard::assertSafe(config('database.default'), config('database.connections.'.config('database.default').'.database'));

[$worker, $count] = [(int) ($argv[1] ?? 0), (int) ($argv[2] ?? 10)];

for ($i = 1; $i <= $count; $i++) {
    DB::transaction(function () use ($worker, $i) {
        app(AuditService::class)->record(AuditAction::Created, null, null, ['worker' => $worker, 'seq' => $i]);
        usleep(random_int(0, 3000)); // segura o lock por um instante para forçar contenção
    });
}
