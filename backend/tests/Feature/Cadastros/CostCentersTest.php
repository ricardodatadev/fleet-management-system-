<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CostCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    $this->token = bearer($this->admin);
    $this->x = Branch::factory()->create(['code' => 'X', 'name' => 'Filial X']);
    $this->y = Branch::factory()->create(['code' => 'Y', 'name' => 'Filial Y']);
});

it('cria (201) com branch embutido {id,code,name}, code normalizado e audit created', function () {
    $res = api('POST', 'cost-centers', ['code' => ' cc-01 ', 'name' => 'Oficina', 'branch_id' => $this->x->id], $this->token)->assertCreated();

    expect($res->json('data'))->toMatchArray([
        'code' => 'CC-01', 'name' => 'Oficina', 'is_active' => true, 'deleted_at' => null,
        'branch' => ['id' => $this->x->id, 'code' => 'X', 'name' => 'Filial X'],
    ]);
    $log = AuditLog::query()->where('auditable_type', (new CostCenter)->getMorphClass())->sole();
    expect($log->action)->toBe('created')->and($log->actor_id)->toBe($this->admin->id);
});

it('sem filial é aceito (branch null)', function () {
    api('POST', 'cost-centers', ['code' => 'CC-G', 'name' => 'Global'], $this->token)->assertCreated()->assertJsonPath('data.branch', null);
    api('POST', 'cost-centers', ['code' => 'CC-G2', 'name' => 'Global', 'branch_id' => null], $this->token)->assertCreated();
});

it('branch_id inexistente ou de filial excluída → 422; filial inativa é aceita', function () {
    $deleted = Branch::factory()->create();
    $deleted->delete();
    $inactive = Branch::factory()->inactive()->create();

    api('POST', 'cost-centers', ['code' => 'A', 'name' => 'A', 'branch_id' => 999999], $this->token)->assertStatus(422)->assertJsonValidationErrors(['branch_id'], 'errors');
    api('POST', 'cost-centers', ['code' => 'A', 'name' => 'A', 'branch_id' => $deleted->id], $this->token)->assertStatus(422)->assertJsonValidationErrors(['branch_id'], 'errors');
    api('POST', 'cost-centers', ['code' => 'A', 'name' => 'A', 'branch_id' => $inactive->id], $this->token)->assertCreated();

    $cc = CostCenter::factory()->create(['branch_id' => $this->x->id]);
    api('PATCH', "cost-centers/{$cc->id}", ['branch_id' => $deleted->id], $this->token)->assertStatus(422);
    api('PATCH', "cost-centers/{$cc->id}", ['branch_id' => null], $this->token)->assertOk()->assertJsonPath('data.branch', null);
});

it('criar/alterar trava a filial (FOR SHARE) para não cruzar com a exclusão dela', function () {
    DB::enableQueryLog();
    api('POST', 'cost-centers', ['code' => 'A', 'name' => 'A', 'branch_id' => $this->x->id], $this->token)->assertCreated();

    expect(collect(DB::getQueryLog())->pluck('query')->implode("\n"))->toContain('select "id" from "branches" where "branches"."id" = ? and "branches"."deleted_at" is null limit 1 for share');
});

it('code duplicado entre ativos → 422; de excluído é permitido; PUT/PATCH parcial ignora o próprio', function () {
    $cc = CostCenter::factory()->create(['code' => 'CC-01', 'name' => 'Antes', 'branch_id' => $this->x->id]);

    api('POST', 'cost-centers', ['code' => 'cc-01', 'name' => 'B'], $this->token)->assertStatus(422)->assertJsonValidationErrors(['code'], 'errors');
    api('PUT', "cost-centers/{$cc->id}", ['code' => 'CC-01', 'name' => 'Depois'], $this->token)
        ->assertOk()->assertJsonPath('data.name', 'Depois')->assertJsonPath('data.branch.id', $this->x->id);

    $cc->delete();
    api('POST', 'cost-centers', ['code' => 'CC-01', 'name' => 'Novo'], $this->token)->assertCreated();
});

it('L/M/O: L vê os da própria filial + os sem filial; outra filial → 404; M/O → 403', function () {
    $inX = CostCenter::factory()->create(['code' => 'CC-X', 'branch_id' => $this->x->id]);
    $inY = CostCenter::factory()->create(['code' => 'CC-Y', 'branch_id' => $this->y->id]);
    $global = CostCenter::factory()->global()->create(['code' => 'CC-G']);

    $leader = bearer(userWithRole(Role::Leader, $this->x));
    expect(api('GET', 'cost-centers', token: $leader)->assertOk()->json('data.*.code'))->toBe(['CC-G', 'CC-X']);
    expect(api('GET', "cost-centers?branch_id={$this->y->id}", token: $leader)->json('data'))->toBe([]);
    api('GET', "cost-centers/{$inX->id}", token: $leader)->assertOk();
    api('GET', "cost-centers/{$global->id}", token: $leader)->assertOk();
    api('GET', "cost-centers/{$inY->id}", token: $leader)->assertNotFound();

    foreach ([Role::Mechanic, Role::Operator] as $role) {
        $token = bearer(userWithRole($role, $this->x));
        api('GET', 'cost-centers', token: $token)->assertForbidden();
        api('GET', "cost-centers/{$inY->id}", token: $token)->assertForbidden();
    }

    expect(api('GET', 'cost-centers', token: $this->token)->json('data.*.code'))->toBe(['CC-G', 'CC-X', 'CC-Y']);
});

it('lista: filtros branch_id/is_active, q e with_trashed (só admin)', function () {
    CostCenter::factory()->create(['code' => 'CC-1', 'name' => 'Pesada', 'branch_id' => $this->x->id]);
    CostCenter::factory()->inactive()->create(['code' => 'CC-2', 'name' => 'Leve', 'branch_id' => $this->y->id]);
    CostCenter::factory()->create(['code' => 'CC-3', 'branch_id' => $this->x->id])->delete();

    $codes = fn (string $query) => api('GET', "cost-centers?{$query}", token: $this->token)->assertOk()->json('data.*.code');
    expect($codes("branch_id={$this->x->id}"))->toBe(['CC-1']);
    expect($codes('is_active=0'))->toBe(['CC-2']);
    expect($codes('q=pesa'))->toBe(['CC-1']);
    expect($codes('with_trashed=1'))->toBe(['CC-1', 'CC-2', 'CC-3']);
    api('GET', 'cost-centers?branch_id=abc', token: $this->token)->assertStatus(422);
    api('GET', 'cost-centers?with_trashed=1', token: bearer(userWithRole(Role::Leader, $this->x)))->assertForbidden();
});

it('exclui (soft delete, audit deleted) e restaura (audit restored); excluído → 404 fora do restore', function () {
    $cc = CostCenter::factory()->create(['branch_id' => $this->x->id]);

    api('DELETE', "cost-centers/{$cc->id}", token: $this->token)->assertOk();
    api('GET', "cost-centers/{$cc->id}", token: $this->token)->assertNotFound();
    api('PATCH', "cost-centers/{$cc->id}", ['name' => 'x'], $this->token)->assertNotFound();

    api('POST', "cost-centers/{$cc->id}/restore", token: $this->token)->assertOk()->assertJsonPath('data.deleted_at', null)->assertJsonPath('data.branch.id', $this->x->id);

    $actions = AuditLog::query()->where('auditable_type', (new CostCenter)->getMorphClass())->where('auditable_id', $cc->id)->orderBy('id')->pluck('action')->all();
    expect($actions)->toBe(['created', 'deleted', 'restored']);
});

it('restore → 409 com code reutilizado ou com a filial vinculada excluída', function () {
    $reused = CostCenter::factory()->create(['code' => 'CC-01', 'branch_id' => $this->x->id]);
    $reused->delete();
    CostCenter::factory()->create(['code' => 'CC-01']);
    api('POST', "cost-centers/{$reused->id}/restore", token: $this->token)->assertStatus(409)->assertJsonPath('message', __('api.restore_code_taken'));

    $branch = Branch::factory()->create();
    $orphan = CostCenter::factory()->create(['branch_id' => $branch->id]);
    $orphan->delete();
    $branch->delete();
    api('POST', "cost-centers/{$orphan->id}/restore", token: $this->token)->assertStatus(409)->assertJsonPath('message', __('api.restore_branch_deleted'));
    expect(CostCenter::withTrashed()->find($orphan->id)->trashed())->toBeTrue();
});

it('validação: code/name obrigatórios no create; branch_id inteiro', function () {
    api('POST', 'cost-centers', [], $this->token)->assertStatus(422)->assertJsonValidationErrors(['code', 'name'], 'errors');
    api('POST', 'cost-centers', ['code' => 'A', 'name' => 'A', 'branch_id' => 'x'], $this->token)->assertStatus(422)->assertJsonValidationErrors(['branch_id'], 'errors');
    api('POST', 'cost-centers', ['code' => str_repeat('A', 31), 'name' => 'A'], $this->token)->assertStatus(422)->assertJsonValidationErrors(['code'], 'errors');
});

it('audit updated grava só os campos alterados (old/new), sem updated_at; PATCH sem mudança não gera evento', function () {
    $cc = CostCenter::factory()->create(['code' => 'CC-01', 'name' => 'Antes', 'branch_id' => $this->x->id]);

    api('PATCH', "cost-centers/{$cc->id}", ['name' => 'Depois', 'branch_id' => $this->y->id], $this->token)->assertOk();
    api('PUT', "cost-centers/{$cc->id}", ['name' => 'Depois'], $this->token)->assertOk(); // sem mudança

    $updated = AuditLog::query()->where('auditable_type', (new CostCenter)->getMorphClass())->where('auditable_id', $cc->id)->where('action', 'updated')->orderBy('id')->get();
    expect($updated)->toHaveCount(1)
        ->and($updated[0]->old_values)->toBe(['name' => 'Antes', 'branch_id' => $this->x->id])
        ->and($updated[0]->new_values)->toBe(['name' => 'Depois', 'branch_id' => $this->y->id])
        ->and($updated[0]->actor_id)->toBe($this->admin->id);
});
