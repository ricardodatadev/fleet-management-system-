<?php

use App\Enums\AuditAction;
use App\Support\Audit\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(AuditService::class)->record(AuditAction::Created, null, null, ['k' => 'v']);
});

// Cada tentativa roda num savepoint (DB::transaction aninhada) para não abortar a transação do teste.
function expectBlocked(string $sql): void
{
    $thrown = null;
    try {
        DB::transaction(fn () => DB::unprepared($sql));
    } catch (QueryException $e) {
        $thrown = $e;
    }
    expect($thrown)->not->toBeNull('o SQL deveria ser bloqueado: '.$sql);
    expect($thrown->getMessage())->toContain('append-only');
}

it('UPDATE via SQL cru falha', function () {
    expectBlocked("UPDATE audit_logs SET action = 'x'");
    expectBlocked('UPDATE audit_logs SET hash = prev_hash WHERE id > 0');
});

it('DELETE via SQL cru falha', function () {
    expectBlocked('DELETE FROM audit_logs');
    expectBlocked('DELETE FROM audit_logs WHERE id > 0');
});

it('TRUNCATE via SQL cru falha', function () {
    expectBlocked('TRUNCATE audit_logs');
    expectBlocked('TRUNCATE TABLE audit_logs RESTART IDENTITY CASCADE');
});

it('INSERT continua permitido e o registro permanece', function () {
    app(AuditService::class)->record(AuditAction::Logout);
    expect(DB::table('audit_logs')->count())->toBe(2);
});
