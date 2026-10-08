<?php

use App\Enums\AuditAction;
use App\Exceptions\AuditImmutableException;
use App\Models\AuditLog;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditHasher;
use App\Support\Audit\AuditService;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\AuditProbe;
use Tests\Support\AuditProbeLimited;

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::create('audit_probes', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('password')->nullable();
        $table->string('remember_token')->nullable();
        $table->text('notes')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
});

function auditLogs(): Collection
{
    return AuditLog::query()->orderBy('id')->get();
}

it('created / updated (só diffs) / deleted / restored', function () {
    $probe = AuditProbe::create(['name' => 'A', 'notes' => 'n1']);
    $probe->update(['name' => 'B']);
    $probe->delete();
    $probe->restore();

    $logs = auditLogs();
    expect($logs->pluck('action')->all())->toBe(['created', 'updated', 'deleted', 'restored']);

    [$created, $updated, $deleted, $restored] = $logs->all();
    expect($created->new_values)->toMatchArray(['name' => 'A', 'notes' => 'n1'])->and($created->old_values)->toBeNull();
    expect($created->auditable_type)->toBe(AuditProbe::class)->and($created->auditable_id)->toBe($probe->id);

    // updated: somente o campo alterado (updated_at é excluído)
    expect($updated->old_values)->toBe(['name' => 'A'])->and($updated->new_values)->toBe(['name' => 'B']);

    expect($deleted->old_values)->toMatchArray(['name' => 'B'])->and($deleted->new_values)->toBeNull();
    expect($deleted->metadata)->toBe(['soft_delete' => true]);
    expect($restored->new_values)->toMatchArray(['name' => 'B']);
});

it('update sem mudança relevante (só updated_at) não gera log', function () {
    $probe = AuditProbe::create(['name' => 'A']);
    $probe->touch();
    expect(auditLogs())->toHaveCount(1);
});

it('password e remember_token nunca aparecem em old/new', function () {
    $probe = AuditProbe::create(['name' => 'A', 'password' => 'segredo-1', 'remember_token' => 'tok-1']);
    $probe->update(['password' => 'segredo-2', 'remember_token' => 'tok-2', 'name' => 'B']);
    $probe->delete();

    $raw = json_encode(DB::table('audit_logs')->get());
    foreach (['segredo-1', 'segredo-2', 'tok-1', 'tok-2', 'password', 'remember_token'] as $secret) {
        expect($raw)->not->toContain($secret);
    }
    expect(auditLogs()->pluck('action')->all())->toBe(['created', 'updated', 'deleted']);

    // mudar só a senha não gera log de update
    $other = AuditProbe::create(['name' => 'C']);
    $before = AuditLog::count();
    $other->update(['password' => 'novo']);
    expect(AuditLog::count())->toBe($before);
});

it('$auditEvents e $auditExclude do model são respeitados (password sempre excluído)', function () {
    $model = new AuditProbeLimited;
    $row = $model->newQuery()->create(['name' => 'Z', 'notes' => 'oculto', 'password' => 'p']);
    $row->update(['name' => 'Z2']);

    $logs = auditLogs();
    expect($logs)->toHaveCount(1);
    expect($logs[0]->new_values)->toHaveKey('name')->not->toHaveKey('notes')->not->toHaveKey('password');
});

it('rollback da transação não deixa log órfão', function () {
    $before = AuditLog::count();

    try {
        DB::transaction(function () {
            AuditProbe::create(['name' => 'vai-sumir']);
            throw new RuntimeException('falha');
        });
    } catch (RuntimeException) {
    }

    expect(AuditLog::count())->toBe($before);
    expect(AuditProbe::count())->toBe(0);

    // e o commit mantém
    DB::transaction(fn () => AuditProbe::create(['name' => 'fica']));
    expect(AuditLog::count())->toBe($before + 1);
});

it('preenche actor_* e source=console fora de HTTP, e source=http/ip/user_agent/request_id numa requisição', function () {
    auth()->setUser(new GenericUser(['id' => 7, 'name' => 'Ana', 'role' => 'admin']));
    AuditProbe::create(['name' => 'console']);
    $console = auditLogs()->last();
    expect($console->source)->toBe('console')->and($console->actor_id)->toBe(7)->and($console->actor_name)->toBe('Ana')->and($console->actor_role)->toBe('admin');
    expect($console->ip)->toBeNull()->and($console->request_id)->toBeNull();
    auth()->forgetGuards();

    Route::post('api/v1/_t/probe', fn () => response()->json(['id' => AuditProbe::create(['name' => 'http'])->id]));
    $rid = (string) Str::uuid();
    $this->withHeaders(['X-Request-Id' => $rid, 'User-Agent' => 'PestAgent/1.0'])->postJson('/api/v1/_t/probe')->assertOk();

    $http = auditLogs()->last();
    expect($http->source)->toBe('http')->and($http->request_id)->toBe($rid)->and($http->user_agent)->toBe('PestAgent/1.0')->and($http->ip)->toBe('127.0.0.1');
});

it('source=queue durante o processamento de um job', function () {
    AuditContext::$inQueueJob = true;
    try {
        AuditProbe::create(['name' => 'job']);
    } finally {
        AuditContext::$inQueueJob = false;
    }
    expect(auditLogs()->last()->source)->toBe('queue');
});

it('encadeia hashes: primeiro prev_hash = 64 zeros e hash = sha256(prev || canonical)', function () {
    AuditProbe::create(['name' => 'A']);
    AuditProbe::create(['name' => 'B']);
    $rows = DB::table('audit_logs')->orderBy('id')->get();

    expect($rows[0]->prev_hash)->toBe(str_repeat('0', 64));
    expect($rows[1]->prev_hash)->toBe($rows[0]->hash);
    foreach ($rows as $row) {
        // reler do banco, recalcular e comparar (event_at/uuid gravados são os mesmos que entraram no hash)
        expect(AuditHasher::hash($row->prev_hash, AuditHasher::fieldsFromRow($row)))->toBe($row->hash);
    }
});

it('só aceita as ações fixas e registra eventos de auth/settings', function () {
    $svc = app(AuditService::class);
    foreach ([AuditAction::LoginSucceeded, AuditAction::LoginFailed, AuditAction::Logout, AuditAction::PasswordChanged, AuditAction::SettingChanged, AuditAction::SettingRemoved] as $action) {
        $svc->record($action, null, null, null, ['email' => 'a@b.c']);
    }
    expect(AuditLog::count())->toBe(6);
    expect(fn () => $svc->record('inventada'))->toThrow(ValueError::class);
});

it('o model AuditLog não permite update/delete/save de existente nem em massa', function () {
    AuditProbe::create(['name' => 'A']);
    $log = AuditLog::query()->first();

    expect(fn () => $log->update(['action' => 'x']))->toThrow(AuditImmutableException::class);
    expect(function () use ($log) {
        $log->action = 'x';
        $log->save();
    })->toThrow(AuditImmutableException::class);
    expect(fn () => $log->delete())->toThrow(AuditImmutableException::class);
    expect(fn () => AuditLog::query()->update(['action' => 'x']))->toThrow(AuditImmutableException::class);
    expect(fn () => AuditLog::query()->delete())->toThrow(AuditImmutableException::class);
});

it('normaliza actor_id/auditable_id string para int (sem falso alarme no audit:verify) e rejeita não inteiros', function () {
    $probe = AuditProbe::create(['name' => 'A']);
    $probe->setAttribute('id', (string) $probe->id);
    auth()->setUser(new GenericUser(['id' => '42', 'name' => 'Ana', 'role' => 'admin']));

    $log = app(AuditService::class)->record(AuditAction::Updated, $probe, ['name' => 'A'], ['name' => 'B']);
    expect($log->actor_id)->toBe(42)->and($log->auditable_id)->toBe((int) $probe->id);
    $this->artisan('audit:verify')->assertExitCode(0);

    auth()->setUser(new GenericUser(['id' => 'abc', 'name' => 'X']));
    expect(fn () => app(AuditService::class)->record(AuditAction::Updated, $probe))->toThrow(InvalidArgumentException::class);
    auth()->forgetGuards();
});
