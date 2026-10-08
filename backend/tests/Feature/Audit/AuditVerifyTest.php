<?php

use App\Enums\AuditAction;
use App\Support\Audit\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function seedChain(int $n = 4): void
{
    $svc = app(AuditService::class);
    for ($i = 1; $i <= $n; $i++) {
        $svc->record(AuditAction::Created, null, null, ['i' => $i, 'txt' => "valor/{$i} é ção"], ['n' => $i]);
    }
}

/** Executa $fn com os triggers do usuário desabilitados (simula adulteração por quem tem acesso ao banco). */
function withoutAuditTriggers(Closure $fn): void
{
    DB::unprepared('ALTER TABLE audit_logs DISABLE TRIGGER USER');
    try {
        $fn();
    } finally {
        DB::unprepared('ALTER TABLE audit_logs ENABLE TRIGGER USER');
    }
}

it('audit:verify = 0 numa cadeia íntegra (inclusive vazia)', function () {
    $this->artisan('audit:verify')->assertExitCode(0);

    seedChain(5);
    $this->artisan('audit:verify')->expectsOutputToContain('5 registro(s)')->assertExitCode(0);
});

it('audit:verify ≠ 0 e aponta o id após adulterar o conteúdo de um registro', function () {
    seedChain(4);
    $ids = DB::table('audit_logs')->orderBy('id')->pluck('id')->all();

    withoutAuditTriggers(fn () => DB::table('audit_logs')->where('id', $ids[2])->update(['new_values' => json_encode(['i' => 999])]));

    $this->artisan('audit:verify')->expectsOutputToContain("id={$ids[2]}")->assertExitCode(1);
});

it('detecta adulteração do hash, do event_at e de campos de ator', function () {
    foreach ([
        ['event_at' => '2020-01-01 00:00:00+00'],
        ['actor_name' => 'Fulano'],
        ['hash' => str_repeat('a', 64)],
    ] as $tamper) {
        DB::unprepared('SAVEPOINT s');
        seedChain(3);
        $id = DB::table('audit_logs')->orderBy('id')->skip(1)->value('id');
        withoutAuditTriggers(fn () => DB::table('audit_logs')->where('id', $id)->update($tamper));
        $this->artisan('audit:verify')->expectsOutputToContain("id={$id}")->assertExitCode(1);
        DB::unprepared('ROLLBACK TO SAVEPOINT s');
    }
});

it('detecta registro removido no meio da cadeia (aponta o seguinte)', function () {
    seedChain(4);
    $ids = DB::table('audit_logs')->orderBy('id')->pluck('id')->all();

    withoutAuditTriggers(fn () => DB::table('audit_logs')->where('id', $ids[1])->delete());

    $this->artisan('audit:verify')->expectsOutputToContain("id={$ids[2]}")->assertExitCode(1);
});
