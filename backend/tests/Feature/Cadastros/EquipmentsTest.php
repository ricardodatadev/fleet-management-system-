<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\EquipmentFamily;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    $this->token = bearer($this->admin);
    $this->x = Branch::factory()->create(['code' => 'X', 'name' => 'Filial X']);
    $this->y = Branch::factory()->create(['code' => 'Y', 'name' => 'Filial Y']);
    $this->family = EquipmentFamily::factory()->create(['code' => 'CAM', 'name' => 'Caminhões', 'criticality' => 'high']);
    $this->ccX = CostCenter::factory()->create(['code' => 'CC-X', 'name' => 'CC X', 'branch_id' => $this->x->id]);
    $this->ccGlobal = CostCenter::factory()->global()->create(['code' => 'CC-G', 'name' => 'CC global']);
});

function equipmentPayload(array $overrides = []): array
{
    return [
        'code' => 'EQ-001', 'name' => 'Caminhão 01',
        'family_id' => test()->family->id, 'branch_id' => test()->x->id, 'cost_center_id' => test()->ccX->id,
        ...$overrides,
    ];
}

function equipmentLogs(int $id): array
{
    return AuditLog::query()->where('auditable_type', (new Equipment)->getMorphClass())->where('auditable_id', $id)->orderBy('id')->pluck('action')->all();
}

it('cria (201) com relações {id,code,name}, criticidade efetiva da família, padrões e audit created', function () {
    $driver = Employee::factory()->driver()->create(['registration' => 'MOT-1', 'name' => 'Maria', 'branch_id' => $this->x->id]);

    $res = api('POST', 'equipments', equipmentPayload(['code' => ' eq-001 ', 'plate' => ' abc-1d23 ', 'responsible_employee_id' => $driver->id, 'year' => 2022]), $this->token)->assertCreated();

    expect($res->json('data'))->toMatchArray([
        'code' => 'EQ-001', 'plate' => 'ABC1D23', 'status' => 'active', 'year' => 2022,
        'criticality' => 'high', 'criticality_source' => 'family', 'criticality_override' => null,
        'family' => ['id' => $this->family->id, 'code' => 'CAM', 'name' => 'Caminhões'],
        'branch' => ['id' => $this->x->id, 'code' => 'X', 'name' => 'Filial X'],
        'cost_center' => ['id' => $this->ccX->id, 'code' => 'CC-X', 'name' => 'CC X'],
        'responsible_employee' => ['id' => $driver->id, 'registration' => 'MOT-1', 'name' => 'Maria'],
        'acquisition_value' => null, 'deleted_at' => null,
    ]);
    expect($res->json('data.odometer_km'))->toEqual(0)->and($res->json('data.hour_meter'))->toEqual(0);
    expect(equipmentLogs($res->json('data.id')))->toBe(['created']);
});

it('odometer_km, hour_meter e acquisition_value saem como número JSON (inclusive relidos do banco)', function () {
    $eq = Equipment::factory()->create(['branch_id' => $this->x->id, 'odometer_km' => 125430.5, 'hour_meter' => 3210, 'acquisition_value' => 450000.75]);

    $raw = api('GET', "equipments/{$eq->id}", token: $this->token)->assertOk()->getContent();
    expect($raw)->toContain('"odometer_km":125430.5')->toContain('"acquisition_value":450000.75')->toMatch('/"hour_meter":3210(\.0)?[,}]/')
        ->not->toMatch('/"(odometer_km|hour_meter|acquisition_value)":"/');
    expect(api('GET', 'equipments', token: $this->token)->getContent())->not->toMatch('/"(odometer_km|hour_meter|acquisition_value)":"/');
});

it('criticality_source: family sem override; override com o valor do equipamento', function () {
    $id = api('POST', 'equipments', equipmentPayload(['criticality_override' => 'low']), $this->token)->assertCreated()
        ->assertJsonPath('data.criticality', 'low')->assertJsonPath('data.criticality_source', 'override')->json('data.id');

    api('PATCH', "equipments/{$id}", ['criticality_override' => null], $this->token)->assertOk()
        ->assertJsonPath('data.criticality', 'high')->assertJsonPath('data.criticality_source', 'family');
    api('PATCH', "equipments/{$id}", ['criticality_override' => 'extreme'], $this->token)->assertStatus(422)->assertJsonValidationErrors(['criticality_override'], 'errors');
});

it('placa: normalizada (maiúsculas, sem espaço nem hífen), formato ^[A-Z0-9]{5,8}$ e única sobre o valor normalizado', function () {
    api('POST', 'equipments', equipmentPayload(['plate' => 'abc-1234']), $this->token)->assertCreated()->assertJsonPath('data.plate', 'ABC1234');

    api('POST', 'equipments', equipmentPayload(['code' => 'EQ-2', 'plate' => 'ABC1234']), $this->token)
        ->assertStatus(422)->assertJsonValidationErrors(['plate'], 'errors');
    api('POST', 'equipments', equipmentPayload(['code' => 'EQ-3', 'plate' => ' a b c 1 2 3 4 ']), $this->token)->assertStatus(422)->assertJsonValidationErrors(['plate'], 'errors');

    foreach (['AB12', 'ABCD12345', 'ABC.1234', 'ÁBC1234'] as $plate) {
        api('POST', 'equipments', equipmentPayload(['code' => 'EQ-F', 'plate' => $plate]), $this->token)
            ->assertStatus(422)->assertJsonPath('errors.plate.0', __('api.equipment_plate_format'));
    }
    // não restringe ao formato brasileiro; sem placa é aceito
    api('POST', 'equipments', equipmentPayload(['code' => 'EQ-4', 'plate' => '12345']), $this->token)->assertCreated();
    api('POST', 'equipments', equipmentPayload(['code' => 'EQ-5']), $this->token)->assertCreated()->assertJsonPath('data.plate', null);
    api('POST', 'equipments', equipmentPayload(['code' => 'EQ-6', 'plate' => '']), $this->token)->assertCreated()->assertJsonPath('data.plate', null);

    // de equipamento excluído é permitida
    Equipment::query()->where('plate', 'ABC1234')->first()->delete();
    api('POST', 'equipments', equipmentPayload(['code' => 'EQ-7', 'plate' => 'abc 1234']), $this->token)->assertCreated();
});

it('code duplicado entre ativos → 422 (normalizado); campos obrigatórios; FKs inexistentes ou excluídas → 422', function () {
    Equipment::factory()->create(['code' => 'EQ-001', 'branch_id' => $this->x->id]);
    $deletedFamily = EquipmentFamily::factory()->create();
    $deletedFamily->delete();

    api('POST', 'equipments', equipmentPayload(['code' => 'eq-001']), $this->token)->assertStatus(422)->assertJsonValidationErrors(['code'], 'errors');
    api('POST', 'equipments', [], $this->token)->assertStatus(422)->assertJsonValidationErrors(['code', 'name', 'family_id', 'branch_id', 'cost_center_id'], 'errors');
    api('POST', 'equipments', equipmentPayload(['code' => 'A', 'family_id' => $deletedFamily->id]), $this->token)->assertStatus(422)->assertJsonValidationErrors(['family_id'], 'errors');
    api('POST', 'equipments', equipmentPayload(['code' => 'B', 'cost_center_id' => null]), $this->token)->assertStatus(422)->assertJsonValidationErrors(['cost_center_id'], 'errors');
    api('POST', 'equipments', equipmentPayload(['code' => 'C', 'status' => 'broken']), $this->token)->assertStatus(422)->assertJsonValidationErrors(['status'], 'errors');
});

it('números: negativos, casas a mais e ano fora de 1950..ano atual + 1 → 422', function (string $field, mixed $value) {
    api('POST', 'equipments', equipmentPayload([$field => $value]), $this->token)->assertStatus(422)->assertJsonValidationErrors([$field], 'errors');
})->with([
    'odômetro negativo' => ['odometer_km', -1],
    'odômetro 2 casas' => ['odometer_km', 10.25],
    'horímetro negativo' => ['hour_meter', -0.5],
    'valor 3 casas' => ['acquisition_value', 10.123],
    'valor negativo' => ['acquisition_value', -1],
    'ano 1949' => ['year', 1949],
    'ano + 2' => ['year', (int) date('Y') + 2],
    'data inválida' => ['acquisition_date', '31/12/2024'],
]);

it('ano no limite (1950 e ano atual + 1) é aceito', function () {
    api('POST', 'equipments', equipmentPayload(['code' => 'A', 'year' => 1950]), $this->token)->assertCreated();
    api('POST', 'equipments', equipmentPayload(['code' => 'B', 'year' => (int) date('Y') + 1]), $this->token)->assertCreated();
});

it('CHECKs e trigger no banco rejeitam violação direta no SQL', function (array $values) {
    $base = ['code' => 'SQL', 'name' => 'SQL', 'family_id' => $this->family->id, 'branch_id' => $this->x->id, 'cost_center_id' => $this->ccX->id];
    $insert = fn () => DB::table('equipments')->insert([...$base, ...$values]);

    expect(fn () => DB::transaction($insert))->toThrow(QueryException::class);
})->with([
    'status' => [['status' => 'broken']],
    'criticality_override' => [['criticality_override' => 'extreme']],
    'ano 1949 (CHECK)' => [['year' => 1949]],
    'ano + 2 (trigger)' => [['year' => (int) date('Y') + 2]],
    'odômetro negativo' => [['odometer_km' => -1]],
    'valor negativo' => [['acquisition_value' => -1]],
]);

it('o trigger de ano também vale no UPDATE, e o banco aceita ano atual + 1', function () {
    $eq = Equipment::factory()->create(['branch_id' => $this->x->id, 'year' => (int) date('Y') + 1]);

    expect(fn () => DB::transaction(fn () => DB::table('equipments')->where('id', $eq->id)->update(['year' => (int) date('Y') + 2])))->toThrow(QueryException::class);
});

it('cost_center_id de outra filial → 422; sem filial → 201; também ao trocar a filial do equipamento', function () {
    $ccY = CostCenter::factory()->create(['branch_id' => $this->y->id]);

    api('POST', 'equipments', equipmentPayload(['code' => 'A', 'cost_center_id' => $ccY->id]), $this->token)
        ->assertStatus(422)->assertJsonPath('errors.cost_center_id.0', __('api.equipment_cost_center_branch_mismatch'));
    api('POST', 'equipments', equipmentPayload(['code' => 'B', 'cost_center_id' => $this->ccGlobal->id]), $this->token)->assertCreated();
    $id = api('POST', 'equipments', equipmentPayload(['code' => 'C']), $this->token)->assertCreated()->json('data.id');

    api('PATCH', "equipments/{$id}", ['branch_id' => $this->y->id], $this->token)->assertStatus(422)->assertJsonValidationErrors(['cost_center_id'], 'errors');
    api('PATCH', "equipments/{$id}", ['branch_id' => $this->y->id, 'cost_center_id' => $ccY->id], $this->token)->assertOk()->assertJsonPath('data.branch.id', $this->y->id);
});

it('responsible_employee_id de outra filial ou excluído → 422; também ao trocar a filial do equipamento', function () {
    $empX = Employee::factory()->create(['branch_id' => $this->x->id]);
    $empY = Employee::factory()->create(['branch_id' => $this->y->id]);
    $deleted = Employee::factory()->create(['branch_id' => $this->x->id]);
    $deleted->delete();

    api('POST', 'equipments', equipmentPayload(['code' => 'A', 'responsible_employee_id' => $empY->id]), $this->token)
        ->assertStatus(422)->assertJsonPath('errors.responsible_employee_id.0', __('api.equipment_responsible_branch_mismatch'));
    api('POST', 'equipments', equipmentPayload(['code' => 'B', 'responsible_employee_id' => $deleted->id]), $this->token)->assertStatus(422)->assertJsonValidationErrors(['responsible_employee_id'], 'errors');
    $id = api('POST', 'equipments', equipmentPayload(['code' => 'C', 'cost_center_id' => $this->ccGlobal->id, 'responsible_employee_id' => $empX->id]), $this->token)->assertCreated()->json('data.id');

    api('PATCH', "equipments/{$id}", ['branch_id' => $this->y->id], $this->token)->assertStatus(422)->assertJsonValidationErrors(['responsible_employee_id'], 'errors');
    api('PATCH', "equipments/{$id}", ['branch_id' => $this->y->id, 'responsible_employee_id' => $empY->id], $this->token)->assertOk();
    api('PATCH', "equipments/{$id}", ['responsible_employee_id' => null], $this->token)->assertOk()->assertJsonPath('data.responsible_employee', null);
});

it('nos dois sentidos: centro de custo ou colaborador mudando de filial com equipamentos de outra → 422', function () {
    $cc = CostCenter::factory()->global()->create();
    $emp = Employee::factory()->create(['branch_id' => $this->x->id]);
    Equipment::factory()->create(['branch_id' => $this->x->id, 'cost_center_id' => $cc->id, 'responsible_employee_id' => $emp->id]);

    api('PATCH', "cost-centers/{$cc->id}", ['branch_id' => $this->y->id], $this->token)
        ->assertStatus(422)->assertJsonPath('errors.branch_id.0', __('api.cost_center_equipments_other_branch'));
    api('PATCH', "cost-centers/{$cc->id}", ['branch_id' => $this->x->id], $this->token)->assertOk();

    api('PATCH', "employees/{$emp->id}", ['branch_id' => $this->y->id], $this->token)
        ->assertStatus(422)->assertJsonPath('errors.branch_id.0', __('api.employee_equipments_other_branch'));
    api('PATCH', "employees/{$emp->id}", ['name' => 'Só o nome'], $this->token)->assertOk();
});

it('criar/alterar trava filial, família, centro de custo e responsável (FOR SHARE)', function () {
    $emp = Employee::factory()->create(['branch_id' => $this->x->id]);

    DB::enableQueryLog();
    api('POST', 'equipments', equipmentPayload(['responsible_employee_id' => $emp->id]), $this->token)->assertCreated();
    expect(collect(DB::getQueryLog())->pluck('query')->implode("\n"))
        ->toContain('from "branches" where "branches"."id" = ? and "branches"."deleted_at" is null limit 1 for share')
        ->toContain('from "equipment_families" where "equipment_families"."id" = ? and "equipment_families"."deleted_at" is null limit 1 for share')
        ->toContain('from "cost_centers" where "cost_centers"."id" = ? and "cost_centers"."deleted_at" is null limit 1 for share')
        ->toContain('from "employees" where "employees"."id" = ? and "employees"."deleted_at" is null limit 1 for share');
});

it('409 ativados: família, centro de custo, colaborador responsável e filial com equipamento não excluído → dependents contém equipments', function () {
    $family = EquipmentFamily::factory()->create();
    $cc = CostCenter::factory()->global()->create();
    $emp = Employee::factory()->create(['branch_id' => $this->y->id]);
    $eq = Equipment::factory()->create(['family_id' => $family->id, 'branch_id' => $this->y->id, 'cost_center_id' => $cc->id, 'responsible_employee_id' => $emp->id]);

    api('DELETE', "equipment-families/{$family->id}", token: $this->token)->assertStatus(409)->assertJsonPath('errors.dependents', ['equipments']);
    api('DELETE', "cost-centers/{$cc->id}", token: $this->token)->assertStatus(409)->assertJsonPath('errors.dependents', ['equipments']);
    api('DELETE', "employees/{$emp->id}", token: $this->token)->assertStatus(409)->assertJsonPath('errors.dependents', ['equipments']);
    api('DELETE', "branches/{$this->y->id}", token: $this->token)->assertStatus(409)->assertJsonPath('errors.dependents', ['employees', 'equipments']);

    $eq->delete(); // equipamento excluído não conta
    api('DELETE', "employees/{$emp->id}", token: $this->token)->assertOk();
    api('DELETE', "equipment-families/{$family->id}", token: $this->token)->assertOk();
    api('DELETE', "cost-centers/{$cc->id}", token: $this->token)->assertOk();
    api('DELETE', "branches/{$this->y->id}", token: $this->token)->assertOk();
});

it('lista: filtros, q em code/name/plate, sort por whitelist (padrão code) e with_trashed', function () {
    $other = EquipmentFamily::factory()->create();
    $emp = Employee::factory()->create(['branch_id' => $this->x->id]);
    Equipment::factory()->create(['code' => 'B-1', 'name' => 'Basculante', 'plate' => 'AAA1111', 'branch_id' => $this->x->id, 'family_id' => $this->family->id, 'year' => 2020, 'responsible_employee_id' => $emp->id]);
    Equipment::factory()->create(['code' => 'A-1', 'name' => 'Trator', 'plate' => 'ZZZ9999', 'branch_id' => $this->y->id, 'family_id' => $other->id, 'status' => 'inactive', 'year' => 2010]);
    Equipment::factory()->create(['code' => 'C-1', 'name' => 'Pá carregadeira', 'branch_id' => $this->x->id, 'family_id' => $this->family->id, 'cost_center_id' => $this->ccX->id, 'status' => 'disposed']);
    Equipment::factory()->create(['code' => 'D-1', 'branch_id' => $this->x->id])->delete();

    $codes = fn (string $query) => api('GET', "equipments{$query}", token: $this->token)->assertOk()->json('data.*.code');

    expect($codes(''))->toBe(['A-1', 'B-1', 'C-1']);
    expect($codes("?family_id={$other->id}"))->toBe(['A-1']);
    expect($codes("?branch_id={$this->x->id}"))->toBe(['B-1', 'C-1']);
    expect($codes("?cost_center_id={$this->ccX->id}"))->toBe(['C-1']);
    expect($codes('?status=inactive'))->toBe(['A-1']);
    expect($codes("?responsible_employee_id={$emp->id}"))->toBe(['B-1']);
    expect($codes('?q=zzz9'))->toBe(['A-1']);
    expect($codes('?q=carreg'))->toBe(['C-1']);
    expect($codes('?q=b-1'))->toBe(['B-1']);
    expect($codes('?sort=-code'))->toBe(['C-1', 'B-1', 'A-1']);
    expect($codes('?sort=year'))->toBe(['A-1', 'B-1', 'C-1']); // null por último
    expect($codes('?sort=status,code'))->toBe(['B-1', 'C-1', 'A-1']); // active, disposed, inactive
    expect($codes('?with_trashed=1'))->toBe(['A-1', 'B-1', 'C-1', 'D-1']);

    foreach (['?sort=created_at', '?sort=odometer_km', '?status=broken', '?family_id=x', '?per_page=101'] as $query) {
        api('GET', "equipments{$query}", token: $this->token)->assertStatus(422);
    }
    foreach (['name', 'plate', 'acquisition_date'] as $sort) {
        api('GET', "equipments?sort={$sort}", token: $this->token)->assertOk();
    }
});

it('escopo: O, M e L veem só a própria filial (outra → 404); só A escreve; with_trashed sem manage → 403', function () {
    $inX = Equipment::factory()->create(['code' => 'EQ-X', 'branch_id' => $this->x->id]);
    $inY = Equipment::factory()->create(['code' => 'EQ-Y', 'branch_id' => $this->y->id]);

    foreach ([Role::Operator, Role::Mechanic, Role::Leader] as $role) {
        $token = bearer(userWithRole($role, $this->x));
        expect(api('GET', 'equipments', token: $token)->assertOk()->json('data.*.code'))->toBe(['EQ-X']);
        expect(api('GET', "equipments?branch_id={$this->y->id}", token: $token)->json('data'))->toBe([]);
        api('GET', "equipments/{$inX->id}", token: $token)->assertOk();
        api('GET', "equipments/{$inY->id}", token: $token)->assertNotFound();
        api('GET', 'equipments?with_trashed=1', token: $token)->assertForbidden();
        api('PATCH', "equipments/{$inX->id}", ['name' => 'X'], $token)->assertForbidden();
        api('POST', 'equipments', equipmentPayload(['code' => 'NOVO']), $token)->assertForbidden();
    }
    expect(api('GET', 'equipments', token: $this->token)->json('data.*.code'))->toBe(['EQ-X', 'EQ-Y']);
});

it('PUT/PATCH parciais com audit updated (old/new só do alterado); excluído → 404; restore com audit', function () {
    $eq = Equipment::factory()->create(['code' => 'EQ-1', 'name' => 'Antes', 'branch_id' => $this->x->id]);

    api('PUT', "equipments/{$eq->id}", ['name' => 'Depois'], $this->token)->assertOk()->assertJsonPath('data.code', 'EQ-1');
    $log = AuditLog::query()->where('auditable_type', $eq->getMorphClass())->where('auditable_id', $eq->id)->where('action', 'updated')->sole();
    expect($log->old_values)->toBe(['name' => 'Antes'])->and($log->new_values)->toBe(['name' => 'Depois']);

    api('DELETE', "equipments/{$eq->id}", token: $this->token)->assertOk();
    api('GET', "equipments/{$eq->id}", token: $this->token)->assertNotFound();
    api('PATCH', "equipments/{$eq->id}", ['name' => 'X'], $this->token)->assertNotFound();
    api('DELETE', "equipments/{$eq->id}", token: $this->token)->assertNotFound();
    api('POST', "equipments/{$eq->id}/restore", token: $this->token)->assertOk()->assertJsonPath('data.deleted_at', null);
    expect(equipmentLogs($eq->id))->toBe(['created', 'updated', 'deleted', 'restored']);
});

it('restore 409: code ou placa reutilizados, família, centro de custo ou filial excluídos, responsável excluído', function () {
    $family = EquipmentFamily::factory()->create();
    $cc = CostCenter::factory()->global()->create();
    $emp = Employee::factory()->create(['branch_id' => $this->x->id]);
    $eq = Equipment::factory()->create(['code' => 'EQ-R', 'plate' => 'RRR1234', 'family_id' => $family->id, 'branch_id' => $this->x->id, 'cost_center_id' => $cc->id, 'responsible_employee_id' => $emp->id]);
    $restore = fn () => api('POST', "equipments/{$eq->id}/restore", token: $this->token);

    $restore()->assertStatus(409); // não excluído
    $eq->delete();

    $reuse = Equipment::factory()->create(['code' => 'EQ-R', 'branch_id' => $this->x->id]);
    $restore()->assertStatus(409)->assertJsonPath('errors.code.0', __('api.restore_code_taken'));
    $reuse->delete();
    $reuse = Equipment::factory()->create(['plate' => 'RRR1234', 'branch_id' => $this->x->id]);
    $restore()->assertStatus(409)->assertJsonPath('errors.plate.0', __('api.restore_plate_taken'));
    $reuse->delete();

    $emp->delete();
    $restore()->assertStatus(409)->assertJsonPath('message', __('api.restore_responsible_unavailable'));
    $emp->restore();
    $family->delete();
    $restore()->assertStatus(409)->assertJsonPath('message', __('api.restore_family_deleted'));
    $family->restore();
    $cc->delete();
    $restore()->assertStatus(409)->assertJsonPath('message', __('api.restore_cost_center_deleted'));
    $cc->restore();

    $restore()->assertOk();
});

it('/meta/enums devolve equipment_statuses', function () {
    api('GET', 'meta/enums', token: $this->token)->assertOk()->assertJsonPath('data.enums.equipment_statuses', ['active', 'inactive', 'disposed']);
});
