<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    $this->token = bearer($this->admin);
});

function branchLogs(Branch|int $branch): array
{
    $id = $branch instanceof Branch ? $branch->id : $branch;

    return AuditLog::query()->where('auditable_type', (new Branch)->getMorphClass())->where('auditable_id', $id)->orderBy('id')->get()->all();
}

it('cria filial (201) normalizando code e state, com deleted_at null e audit created', function () {
    $res = api('POST', 'branches', ['code' => '  fil-01 ', 'name' => 'Matriz', 'type' => 'oficina', 'city' => 'Goiânia', 'state' => 'go'], $this->token)
        ->assertCreated()->assertJsonPath('status', 'success');

    expect($res->json('data'))->toMatchArray([
        'code' => 'FIL-01', 'name' => 'Matriz', 'type' => 'oficina', 'city' => 'Goiânia', 'state' => 'GO', 'is_active' => true, 'deleted_at' => null,
    ])->toHaveKeys(['id', 'created_at', 'updated_at']);

    $log = branchLogs($res->json('data.id'))[0];
    expect($log->action)->toBe('created')->and($log->actor_id)->toBe($this->admin->id)->and($log->new_values['code'])->toBe('FIL-01');
});

it('valida o payload de criação (422 por campo)', function (array $payload, array $fields) {
    api('POST', 'branches', $payload, $this->token)->assertStatus(422)->assertJsonValidationErrors($fields, 'errors');
})->with([
    'vazio' => [[], ['code', 'name', 'type']],
    'tipo inválido' => [['code' => 'A', 'name' => 'A', 'type' => 'deposito'], ['type']],
    'UF inválida' => [['code' => 'A', 'name' => 'A', 'type' => 'filial', 'state' => 'XX'], ['state']],
    'code longo' => [['code' => str_repeat('A', 21), 'name' => 'A', 'type' => 'filial'], ['code']],
    'is_active não booleano' => [['code' => 'A', 'name' => 'A', 'type' => 'filial', 'is_active' => 'talvez'], ['is_active']],
]);

it('code duplicado entre ativos → 422 (também com caixa/espaços diferentes); de registro excluído é permitido', function () {
    $old = Branch::factory()->create(['code' => 'FIL-01']);

    api('POST', 'branches', ['code' => ' fil-01', 'name' => 'B', 'type' => 'filial'], $this->token)
        ->assertStatus(422)->assertJsonValidationErrors(['code'], 'errors');

    $old->delete();
    api('POST', 'branches', ['code' => 'FIL-01', 'name' => 'B', 'type' => 'filial'], $this->token)->assertCreated();
});

it('PUT e PATCH são parciais: só os campos enviados mudam; unicidade ignora o próprio registro', function (string $method) {
    $branch = Branch::factory()->create(['code' => 'FIL-01', 'name' => 'Antes', 'type' => 'filial', 'city' => 'Anápolis']);
    Branch::factory()->create(['code' => 'FIL-02']);

    api($method, "branches/{$branch->id}", ['name' => 'Depois', 'code' => 'fil-01'], $this->token)
        ->assertOk()->assertJsonPath('data.name', 'Depois')->assertJsonPath('data.city', 'Anápolis')->assertJsonPath('data.code', 'FIL-01');
    api($method, "branches/{$branch->id}", ['code' => 'FIL-02'], $this->token)->assertStatus(422)->assertJsonValidationErrors(['code'], 'errors');
    api($method, "branches/{$branch->id}", ['name' => ''], $this->token)->assertStatus(422)->assertJsonValidationErrors(['name'], 'errors');

    $updated = collect(branchLogs($branch))->where('action', 'updated')->values();
    expect($updated)->toHaveCount(1)->and($updated[0]->old_values)->toBe(['name' => 'Antes'])->and($updated[0]->new_values)->toBe(['name' => 'Depois']);
})->with(['PUT', 'PATCH']);

it('show 200; registro excluído ou inexistente → 404 em show/update/delete', function () {
    $branch = Branch::factory()->create();
    api('GET', "branches/{$branch->id}", token: $this->token)->assertOk()->assertJsonPath('data.id', $branch->id)->assertJsonPath('data.deleted_at', null);

    $branch->delete();
    api('GET', "branches/{$branch->id}", token: $this->token)->assertNotFound();
    api('PUT', "branches/{$branch->id}", ['name' => 'x'], $this->token)->assertNotFound();
    api('DELETE', "branches/{$branch->id}", token: $this->token)->assertNotFound();
    api('GET', 'branches/999999', token: $this->token)->assertNotFound();
});

it('exclui (soft delete) com audit deleted', function () {
    $branch = Branch::factory()->create();

    api('DELETE', "branches/{$branch->id}", token: $this->token)->assertOk()->assertJsonPath('data', null);

    expect(Branch::withTrashed()->find($branch->id)->deleted_at)->not->toBeNull();
    $log = collect(branchLogs($branch))->last();
    expect($log->action)->toBe('deleted')->and($log->metadata)->toBe(['soft_delete' => true]);
});

it('excluir filial com dependentes ativos → 409 (centros de custo, mesmo inativos, e usuários)', function () {
    $withCostCenter = Branch::factory()->create();
    CostCenter::factory()->inactive()->create(['branch_id' => $withCostCenter->id]);
    $withUser = Branch::factory()->create();
    User::factory()->create(['branch_id' => $withUser->id]);

    api('DELETE', "branches/{$withCostCenter->id}", token: $this->token)
        ->assertStatus(409)->assertJsonPath('errors.dependents', ['cost_centers'])->assertJsonPath('status', 'error');
    api('DELETE', "branches/{$withUser->id}", token: $this->token)->assertStatus(409)->assertJsonPath('errors.dependents', ['users']);

    expect(Branch::query()->whereKey([$withCostCenter->id, $withUser->id])->count())->toBe(2);
    expect(collect(branchLogs($withCostCenter))->where('action', 'deleted'))->toBeEmpty();
});

it('dependentes excluídos não bloqueiam a exclusão', function () {
    $branch = Branch::factory()->create();
    CostCenter::factory()->create(['branch_id' => $branch->id])->delete();
    User::factory()->create(['branch_id' => $branch->id])->delete();

    api('DELETE', "branches/{$branch->id}", token: $this->token)->assertOk();
});

it('a exclusão trava a linha da filial (FOR UPDATE) na mesma transação da checagem', function () {
    $branch = Branch::factory()->create();
    DB::enableQueryLog();

    api('DELETE', "branches/{$branch->id}", token: $this->token)->assertOk();

    $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    expect($sql)->toMatch('/select .* from "branches" where "branches"\."id" = \? limit 1 for update/');
});

it('restore: 200 com audit restored; 409 se não estiver excluída ou se o code foi reutilizado', function () {
    $branch = Branch::factory()->create(['code' => 'FIL-01']);
    api('POST', "branches/{$branch->id}/restore", token: $this->token)->assertStatus(409)->assertJsonPath('message', __('api.restore_not_deleted'));

    $branch->delete();
    api('POST', "branches/{$branch->id}/restore", token: $this->token)->assertOk()->assertJsonPath('data.deleted_at', null);
    expect(collect(branchLogs($branch))->pluck('action')->all())->toBe(['created', 'deleted', 'restored']);

    $branch->delete();
    Branch::factory()->create(['code' => 'FIL-01']);
    api('POST', "branches/{$branch->id}/restore", token: $this->token)
        ->assertStatus(409)->assertJsonPath('message', __('api.restore_code_taken'));
    api('POST', 'branches/999999/restore', token: $this->token)->assertNotFound();
});

it('lista: filtros type/is_active, q (code/name, % e _ literais) e meta', function () {
    Branch::factory()->create(['code' => 'GAR-1', 'name' => 'Garagem Norte', 'type' => 'garagem']);
    Branch::factory()->create(['code' => 'OFI-1', 'name' => 'Oficina 100%', 'type' => 'oficina', 'is_active' => false]);
    Branch::factory()->create(['code' => 'FIL_1', 'name' => 'Filial Sul', 'type' => 'filial']);
    Branch::factory()->create(['code' => 'FILX1', 'name' => 'Filial Leste', 'type' => 'filial']);

    $codes = fn (string $query) => api('GET', "branches?{$query}", token: $this->token)->assertOk()->json('data.*.code');

    expect($codes('type=filial'))->toBe(['FIL_1', 'FILX1']);
    expect($codes('is_active=0'))->toBe(['OFI-1']);
    expect($codes('q=norte'))->toBe(['GAR-1']);
    expect($codes('q=100%25'))->toBe(['OFI-1']);
    expect($codes('q=FIL_'))->toBe(['FIL_1']); // _ não é curinga
    expect(api('GET', 'branches?per_page=2&page=2', token: $this->token)->json('meta'))
        ->toBe(['current_page' => 2, 'per_page' => 2, 'total' => 4, 'last_page' => 2]);
});

it('lista: sort multi-campo com whitelist (padrão code); sort inválido → 422', function () {
    Branch::factory()->create(['code' => 'B', 'name' => 'Zeta', 'type' => 'filial']);
    Branch::factory()->create(['code' => 'A', 'name' => 'Alfa', 'type' => 'oficina']);
    Branch::factory()->create(['code' => 'C', 'name' => 'Alfa', 'type' => 'filial']);

    $codes = fn (string $query) => api('GET', "branches?{$query}", token: $this->token)->assertOk()->json('data.*.code');
    expect($codes(''))->toBe(['A', 'B', 'C']);
    expect($codes('sort=-code'))->toBe(['C', 'B', 'A']);
    expect($codes('sort=name,-code'))->toBe(['C', 'A', 'B']);
    expect($codes('sort=type,code'))->toBe(['B', 'C', 'A']);

    foreach (['sort=password', 'sort=code;drop', 'sort=--code', 'sort=code,'] as $bad) {
        api('GET', "branches?{$bad}", token: $this->token)->assertStatus(422)->assertJsonValidationErrors(['sort'], 'errors');
    }
});

it('lista: per_page máx. 100, ou 200 com is_active=1; valores inválidos → 422', function () {
    api('GET', 'branches?per_page=100', token: $this->token)->assertOk();
    api('GET', 'branches?per_page=101', token: $this->token)->assertStatus(422)->assertJsonValidationErrors(['per_page'], 'errors');
    api('GET', 'branches?per_page=200&is_active=1', token: $this->token)->assertOk()->assertJsonPath('meta.per_page', 200);
    api('GET', 'branches?per_page=201&is_active=1', token: $this->token)->assertStatus(422);
    api('GET', 'branches?per_page=200&is_active=0', token: $this->token)->assertStatus(422);
    api('GET', 'branches?type=deposito', token: $this->token)->assertStatus(422)->assertJsonValidationErrors(['type'], 'errors');
    api('GET', 'branches?page=0', token: $this->token)->assertStatus(422);
    api('GET', 'branches?desconhecido=1', token: $this->token)->assertOk(); // filtros fora da lista são ignorados
});

it('with_trashed=1: admin vê excluídas; sem branches.manage → 403', function () {
    Branch::factory()->create(['code' => 'ATIVA']);
    Branch::factory()->create(['code' => 'EXCLUIDA'])->delete();

    expect(api('GET', 'branches', token: $this->token)->json('data.*.code'))->toBe(['ATIVA']);
    $res = api('GET', 'branches?with_trashed=1', token: $this->token)->assertOk();
    expect($res->json('data.*.code'))->toBe(['ATIVA', 'EXCLUIDA']);
    expect($res->json('data.1.deleted_at'))->not->toBeNull();

    foreach ([Role::Operator, Role::Mechanic, Role::Leader] as $role) {
        $token = bearer(userWithRole($role, Branch::factory()->create()));
        api('GET', 'branches?with_trashed=1', token: $token)->assertForbidden();
        api('GET', 'branches', token: $token)->assertOk();
    }
});

it('meta/enums devolve branch_types', function () {
    api('GET', 'meta/enums', token: $this->token)->assertOk()->assertJsonPath('data.enums.branch_types', ['filial', 'garagem', 'oficina']);
});
