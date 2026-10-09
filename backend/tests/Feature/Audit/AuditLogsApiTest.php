<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Support\Audit\Auditable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    $this->token = bearer($this->admin);
});

/**
 * Força event_at de um log (o trigger bloqueia UPDATE; desliga só nesta conexão de teste). O hash
 * deixa de bater, o que não importa aqui: o teste é de consulta.
 */
function setEventAt(int $id, string $at): void
{
    DB::unprepared('ALTER TABLE audit_logs DISABLE TRIGGER USER');
    DB::table('audit_logs')->where('id', $id)->update(['event_at' => $at]);
    DB::unprepared('ALTER TABLE audit_logs ENABLE TRIGGER USER');
}

it('item no formato da F.1: actor {id,name,role}, auditable {type (alias), id} e event_at UTC com microssegundos', function () {
    $branch = api('POST', 'branches', ['code' => 'F1', 'name' => 'Filial', 'type' => 'filial'], $this->token)->json('data');

    $item = api('GET', 'audit-logs?auditable_type=branch', token: $this->token)->assertOk()->json('data.0');

    expect(array_keys($item))->toBe(['id', 'uuid', 'event_at', 'actor', 'action', 'auditable', 'old_values', 'new_values', 'metadata', 'ip', 'request_id']);
    expect($item['actor'])->toBe(['id' => $this->admin->id, 'name' => $this->admin->name, 'role' => 'admin']);
    expect($item['auditable'])->toBe(['type' => 'branch', 'id' => $branch['id']]);
    expect($item['action'])->toBe('created')->and($item['new_values']['code'])->toBe('F1');
    expect($item['event_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
    expect($item['request_id'])->toBeString()->and(Str::isUuid($item['uuid']))->toBeTrue();

    api('GET', "audit-logs/{$item['id']}", token: $this->token)->assertOk()->assertJsonPath('data', $item);
});

it('actor null e auditable null em login_failed (ação sem usuário)', function () {
    api('POST', 'auth/login', ['email' => 'ninguem@example.com', 'password' => 'Errada12345', 'device_name' => 'pest'])->assertStatus(422);

    $item = api('GET', 'audit-logs?action=login_failed', token: $this->token)->assertOk()->json('data.0');
    expect($item['actor'])->toBeNull()->and($item['auditable'])->toBeNull()->and($item['metadata']['reason'])->toBe('invalid_credentials');
});

it('filtra por auditable_type, auditable_id, actor_id, action e request_id', function () {
    $other = userWithRole(Role::Admin);
    $otherToken = bearer($other);
    $branch = Branch::factory()->create(); // sem ator (fábrica fora de requisição)
    $cc = api('POST', 'cost-centers', ['code' => 'CC-1', 'name' => 'Um'], $this->token)->json('data');
    api('PATCH', "cost-centers/{$cc['id']}", ['name' => 'Dois'], $otherToken)->assertOk();

    $actions = fn (string $query) => collect(api('GET', "audit-logs?{$query}", token: $this->token)->assertOk()->json('data'))
        ->map(fn ($i) => $i['action'].':'.($i['auditable']['type'] ?? '-'))->all();

    expect($actions('auditable_type=cost_center'))->toBe(['updated:cost_center', 'created:cost_center']);
    expect($actions("auditable_type=branch&auditable_id={$branch->id}"))->toBe(['created:branch']);
    expect($actions("actor_id={$other->id}"))->toBe(['updated:cost_center']);
    expect($actions('action=updated&auditable_type=cost_center'))->toBe(['updated:cost_center']);

    $requestId = AuditLog::query()->where('action', 'updated')->value('request_id');
    expect($actions("request_id={$requestId}"))->toBe(['updated:cost_center']);
    expect(api('GET', 'audit-logs?request_id='.Str::uuid(), token: $this->token)->json('data'))->toBe([]);
});

it('auditable_type desconhecido, action fora do enum e parâmetros malformados → 422', function () {
    foreach (['auditable_type=equipment', 'auditable_type=App%5CModels%5CUser', 'action=hacked', 'auditable_id=x', 'actor_id=x', 'request_id=123', 'from=ontem', 'per_page=101'] as $query) {
        api('GET', "audit-logs?{$query}", token: $this->token)->assertStatus(422);
    }
});

it('from/to inclusivos sobre event_at (ISO 8601); data sem hora cobre o dia; from > to → 422', function () {
    $ids = collect(range(1, 3))->map(fn ($i) => CostCenter::factory()->global()->create()->id)->all();
    $logs = AuditLog::query()->where('auditable_type', (new CostCenter)->getMorphClass())->orderBy('id')->pluck('id')->all();
    setEventAt($logs[0], '2026-10-01 00:00:00+00');
    setEventAt($logs[1], '2026-10-05 23:59:59.999999+00');
    setEventAt($logs[2], '2026-10-06 00:00:00+00');

    $found = fn (string $query) => collect(api('GET', "audit-logs?auditable_type=cost_center&{$query}", token: $this->token)->assertOk()->json('data.*.auditable.id'))->sort()->values()->all();

    expect($found('from=2026-10-01T00:00:00Z&to=2026-10-06T00:00:00Z'))->toBe($ids);
    expect($found('from=2026-10-01T00:00:00.000001Z'))->toBe([$ids[1], $ids[2]]);
    expect($found('to=2026-10-05'))->toBe([$ids[0], $ids[1]]);
    expect($found('from=2026-10-05&to=2026-10-05'))->toBe([$ids[1]]);
    expect($found('from=2026-10-05T21:00:00-03:00'))->toBe([$ids[2]]); // = 2026-10-06T00:00Z

    api('GET', 'audit-logs?from=2026-10-06&to=2026-10-05', token: $this->token)->assertStatus(422)->assertJsonValidationErrors(['to'], 'errors');
});

it('ordenação fixa event_at desc, id desc (sort é ignorado)', function () {
    CostCenter::factory()->global()->count(3)->create();
    $logs = AuditLog::query()->where('auditable_type', (new CostCenter)->getMorphClass())->orderBy('id')->pluck('id')->all();
    setEventAt($logs[0], '2026-10-09 12:00:00+00');
    setEventAt($logs[1], '2026-10-09 12:00:00+00');
    setEventAt($logs[2], '2026-10-08 12:00:00+00');

    $expected = [$logs[1], $logs[0], $logs[2]];
    expect(api('GET', 'audit-logs?auditable_type=cost_center', token: $this->token)->json('data.*.id'))->toBe($expected);
    expect(api('GET', 'audit-logs?auditable_type=cost_center&sort=id', token: $this->token)->assertOk()->json('data.*.id'))->toBe($expected);
});

it('paginação segue a convenção (meta) e show de id inexistente → 404', function () {
    CostCenter::factory()->global()->count(3)->create();

    $res = api('GET', 'audit-logs?auditable_type=cost_center&per_page=2', token: $this->token)->assertOk();
    expect($res->json('meta'))->toMatchArray(['per_page' => 2, 'total' => 3, 'last_page' => 2]);

    api('GET', 'audit-logs/999999', token: $this->token)->assertNotFound()->assertJsonPath('status', 'error');
});

it('não existe escrita em /audit-logs: POST/PUT/PATCH/DELETE → 405, nem para admin, e nada muda', function () {
    $id = AuditLog::query()->value('id');
    $count = AuditLog::query()->count();

    api('POST', 'audit-logs', ['action' => 'created'], $this->token)->assertStatus(405);
    foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
        api($method, "audit-logs/{$id}", ['action' => 'hacked'], $this->token)->assertStatus(405);
        api($method, 'audit-logs', [], $this->token)->assertStatus(405);
    }
    api('POST', "audit-logs/{$id}", [], $this->token)->assertStatus(405);

    expect(AuditLog::query()->count())->toBe($count)->and(AuditLog::query()->find($id)->action)->not->toBe('hacked');
});

it('somente admin: L/M/O → 403 na lista e no detalhe', function (Role $role) {
    $token = bearer(userWithRole($role, Branch::factory()->create()));
    $id = AuditLog::query()->value('id');

    api('GET', 'audit-logs', token: $token)->assertForbidden();
    api('GET', "audit-logs/{$id}", token: $token)->assertForbidden();
})->with([Role::Leader, Role::Mechanic, Role::Operator]);

it('alias de auditable_type cobre todos os models com Auditable', function () {
    $auditable = collect(glob(app_path('Models/*.php')))
        ->map(fn (string $file) => 'App\\Models\\'.basename($file, '.php'))
        ->filter(fn (string $class) => in_array(Auditable::class, class_uses_recursive($class), true))
        ->sort()->values()->all();

    expect(collect(AuditLog::AUDITABLE_TYPES)->values()->sort()->values()->all())->toBe($auditable);
    foreach (array_keys(AuditLog::AUDITABLE_TYPES) as $alias) {
        expect(AuditLog::aliasFor(AuditLog::morphClassFor($alias)))->toBe($alias);
    }
});
