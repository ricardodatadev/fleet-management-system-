<?php

use App\Support\Audit\AuditChainVerifier;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

/**
 * Concorrência real: 2 PROCESSOS PHP independentes (cada um com sua conexão e suas transações) gravam
 * eventos ao mesmo tempo; o pg_advisory_xact_lock deve serializar e manter a cadeia válida.
 * Usa uma conexão própria (autocommit) porque os filhos precisam enxergar/commitar fora da transação do teste.
 */
function auditAdmin(): Connection
{
    config(['database.connections.audit_admin' => config('database.connections.'.config('database.default'))]);

    return DB::connection('audit_admin');
}

/** Limpa audit_logs fora do trigger (só em teste, no banco *_test). */
function wipeAuditLogs(): void
{
    $c = auditAdmin();
    $c->unprepared('ALTER TABLE audit_logs DISABLE TRIGGER USER');
    $c->unprepared('TRUNCATE audit_logs RESTART IDENTITY');
    $c->unprepared('ALTER TABLE audit_logs ENABLE TRIGGER USER');
}

beforeEach(fn () => wipeAuditLogs());
afterEach(fn () => wipeAuditLogs());

it('2 processos concorrentes mantêm a cadeia de hash válida e sem forks', function () {
    $perWorker = 25;
    $env = [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => config('database.connections.pgsql.database'),
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
    ];

    $procs = array_map(function (int $worker) use ($env, $perWorker) {
        $p = new Process([PHP_BINARY, base_path('tests/Support/audit_writer.php'), (string) $worker, (string) $perWorker], base_path(), $env);
        $p->setTimeout(120);
        $p->start();

        return $p;
    }, [1, 2]);

    foreach ($procs as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getErrorOutput().$p->getOutput());
    }

    $conn = auditAdmin();
    expect($conn->table('audit_logs')->count())->toBe(2 * $perWorker);
    // nenhum fork: cada prev_hash aparece exatamente uma vez
    expect($conn->table('audit_logs')->distinct()->count('prev_hash'))->toBe(2 * $perWorker);
    // ambos os workers realmente intercalaram
    $workers = $conn->table('audit_logs')->orderBy('id')->pluck('new_values')->map(fn ($v) => json_decode($v, true)['worker'])->all();
    expect(count(array_unique($workers)))->toBe(2);

    $result = app(AuditChainVerifier::class)->verify('audit_admin');
    expect($result)->toMatchArray(['ok' => true, 'checked' => 2 * $perWorker, 'first_bad_id' => null]);
});
