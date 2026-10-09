<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    $this->token = bearer($this->admin);
    $this->x = Branch::factory()->create(['code' => 'X', 'name' => 'Filial X']);
    $this->y = Branch::factory()->create(['code' => 'Y', 'name' => 'Filial Y']);
});

function employeePayload(array $overrides = []): array
{
    return ['registration' => 'MAT-001', 'name' => 'João Pereira', 'job_type' => 'leader', ...$overrides];
}

function employeeLogs(int $id): array
{
    return AuditLog::query()->where('auditable_type', (new Employee)->getMorphClass())->where('auditable_id', $id)->orderBy('id')->pluck('action')->all();
}

it('cria (201) com branch, cost_center e user embutidos, matrícula normalizada e audit created', function () {
    $cc = CostCenter::factory()->create(['code' => 'CC-X', 'name' => 'Oficina X', 'branch_id' => $this->x->id]);
    $user = userWithRole(Role::Mechanic, $this->x);

    $res = api('POST', 'employees', employeePayload([
        'registration' => ' mat-001 ', 'job_type' => 'mechanic', 'branch_id' => $this->x->id, 'cost_center_id' => $cc->id,
        'user_id' => $user->id, 'phone' => '62 99999-0000', 'hired_at' => '2024-03-01', 'specialty' => 'Diesel', 'hourly_cost' => 85.5,
    ]), $this->token)->assertCreated();

    expect($res->json('data'))->toMatchArray([
        'registration' => 'MAT-001', 'job_type' => 'mechanic', 'hired_at' => '2024-03-01', 'specialty' => 'Diesel', 'hourly_cost' => 85.5,
        'cnh_number' => null, 'cnh_category' => null, 'cnh_expires_at' => null, 'is_active' => true, 'deleted_at' => null,
        'branch' => ['id' => $this->x->id, 'code' => 'X', 'name' => 'Filial X'],
        'cost_center' => ['id' => $cc->id, 'code' => 'CC-X', 'name' => 'Oficina X'],
        'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
    ]);
    expect(employeeLogs($res->json('data.id')))->toBe(['created']);
});

it('hourly_cost sai como número JSON, nunca string (inclusive relido do banco)', function () {
    $employee = Employee::factory()->mechanic()->create(['branch_id' => $this->x->id, 'hourly_cost' => 120]);

    $raw = api('GET', "employees/{$employee->id}", token: $this->token)->assertOk()->getContent();
    expect($raw)->toMatch('/"hourly_cost":120(\.0)?[,}]/')->not->toContain('"hourly_cost":"');
    expect(api('GET', 'employees', token: $this->token)->getContent())->not->toContain('"hourly_cost":"');
});

it('matrícula duplicada entre ativos → 422 (sem diferenciar caixa); de excluído é permitida', function () {
    $other = Employee::factory()->create(['registration' => 'MAT-001', 'branch_id' => $this->x->id]);

    api('POST', 'employees', employeePayload(['registration' => 'mat-001', 'branch_id' => $this->x->id]), $this->token)->assertStatus(422)->assertJsonValidationErrors(['registration'], 'errors');
    api('PATCH', "employees/{$other->id}", ['registration' => 'MAT-001'], $this->token)->assertOk();

    $other->delete();
    api('POST', 'employees', employeePayload(['branch_id' => $this->x->id]), $this->token)->assertCreated();
});

it('campos obrigatórios, job_type e cnh_category fora do enum, filial inexistente ou excluída → 422', function () {
    $deleted = Branch::factory()->create();
    $deleted->delete();

    api('POST', 'employees', [], $this->token)->assertStatus(422)->assertJsonValidationErrors(['registration', 'name', 'job_type', 'branch_id'], 'errors');
    api('POST', 'employees', employeePayload(['job_type' => 'pilot', 'branch_id' => $this->x->id]), $this->token)->assertStatus(422)->assertJsonValidationErrors(['job_type'], 'errors');
    api('POST', 'employees', employeePayload(['job_type' => 'driver', 'cnh_category' => 'F', 'branch_id' => $this->x->id]), $this->token)->assertStatus(422)->assertJsonValidationErrors(['cnh_category'], 'errors');
    api('POST', 'employees', employeePayload(['branch_id' => $deleted->id]), $this->token)->assertStatus(422)->assertJsonValidationErrors(['branch_id'], 'errors');
    api('POST', 'employees', employeePayload(['branch_id' => $this->x->id, 'hired_at' => '01/03/2024']), $this->token)->assertStatus(422)->assertJsonValidationErrors(['hired_at'], 'errors');
});

it('campos por job_type: valor em campo de outro tipo → 422; null é aceito', function (string $type, string $field, mixed $value) {
    api('POST', 'employees', employeePayload(['job_type' => $type, 'branch_id' => $this->x->id, $field => $value]), $this->token)
        ->assertStatus(422)->assertJsonValidationErrors([$field], 'errors');
    api('POST', 'employees', employeePayload(['job_type' => $type, 'branch_id' => $this->x->id, $field => null]), $this->token)->assertCreated();
})->with([
    'CNH em mecânico' => ['mechanic', 'cnh_number', '12345678901'],
    'categoria CNH em mecânico' => ['mechanic', 'cnh_category', 'D'],
    'validade CNH em líder' => ['leader', 'cnh_expires_at', '2030-01-01'],
    'especialidade em motorista' => ['driver', 'specialty', 'Elétrica'],
    'custo/hora em motorista' => ['driver', 'hourly_cost', 50],
    'custo/hora em equipe adm' => ['admin_staff', 'hourly_cost', 0],
]);

it('motorista aceita CNH; mecânico aceita especialidade e custo/hora ≥ 0 com até 2 casas', function () {
    api('POST', 'employees', employeePayload(['registration' => 'MOT', 'job_type' => 'driver', 'branch_id' => $this->x->id, 'cnh_number' => '123', 'cnh_category' => 'AE', 'cnh_expires_at' => '2030-12-31']), $this->token)
        ->assertCreated()->assertJsonPath('data.cnh_category', 'AE')->assertJsonPath('data.cnh_expires_at', '2030-12-31');
    api('POST', 'employees', employeePayload(['registration' => 'MEC', 'job_type' => 'mechanic', 'branch_id' => $this->x->id, 'hourly_cost' => 0]), $this->token)->assertCreated();

    foreach ([-1, 10.555, 'caro'] as $value) {
        api('POST', 'employees', employeePayload(['registration' => 'MEC2', 'job_type' => 'mechanic', 'branch_id' => $this->x->id, 'hourly_cost' => $value]), $this->token)
            ->assertStatus(422)->assertJsonValidationErrors(['hourly_cost'], 'errors');
    }
});

it('troca de job_type: campos preenchidos do tipo anterior precisam vir como null (senão 422); sem limpeza silenciosa', function () {
    $driver = Employee::factory()->driver()->create(['branch_id' => $this->x->id, 'cnh_expires_at' => null]); // cnh_number e cnh_category preenchidos

    api('PATCH', "employees/{$driver->id}", ['job_type' => 'mechanic'], $this->token)
        ->assertStatus(422)->assertJsonValidationErrors(['cnh_number', 'cnh_category'], 'errors')->assertJsonMissingValidationErrors(['cnh_expires_at'], 'errors');
    api('PATCH', "employees/{$driver->id}", ['job_type' => 'mechanic', 'cnh_number' => null], $this->token)
        ->assertStatus(422)->assertJsonValidationErrors(['cnh_category'], 'errors');
    expect($driver->refresh()->job_type)->toBe('driver')->and($driver->cnh_number)->not->toBeNull();

    api('PUT', "employees/{$driver->id}", ['job_type' => 'mechanic', 'cnh_number' => null, 'cnh_category' => null, 'specialty' => 'Freios'], $this->token)
        ->assertOk()->assertJsonPath('data.job_type', 'mechanic')->assertJsonPath('data.cnh_number', null)->assertJsonPath('data.specialty', 'Freios');

    // tipo sem campos próprios → outro: nada a limpar
    $leader = Employee::factory()->create(['branch_id' => $this->x->id]);
    api('PATCH', "employees/{$leader->id}", ['job_type' => 'admin_staff'], $this->token)->assertOk();
    // mesmo tipo: campos do próprio tipo continuam editáveis
    $mechanic = Employee::factory()->mechanic()->create(['branch_id' => $this->x->id]);
    api('PATCH', "employees/{$mechanic->id}", ['hourly_cost' => 99.9], $this->token)->assertOk()->assertJsonPath('data.hourly_cost', 99.9);
    api('PATCH', "employees/{$mechanic->id}", ['cnh_number' => '1'], $this->token)->assertStatus(422)->assertJsonValidationErrors(['cnh_number'], 'errors');
});

it('cost_center_id: mesma filial ou sem filial; de outra filial ou excluído → 422 (também ao trocar a filial do colaborador)', function () {
    $ccX = CostCenter::factory()->create(['branch_id' => $this->x->id]);
    $ccY = CostCenter::factory()->create(['branch_id' => $this->y->id]);
    $global = CostCenter::factory()->global()->create();
    $deleted = CostCenter::factory()->create(['branch_id' => $this->x->id]);
    $deleted->delete();

    api('POST', 'employees', employeePayload(['registration' => 'A', 'branch_id' => $this->x->id, 'cost_center_id' => $ccY->id]), $this->token)
        ->assertStatus(422)->assertJsonPath('errors.cost_center_id.0', __('api.employee_cost_center_branch_mismatch'));
    api('POST', 'employees', employeePayload(['registration' => 'B', 'branch_id' => $this->x->id, 'cost_center_id' => $deleted->id]), $this->token)->assertStatus(422)->assertJsonValidationErrors(['cost_center_id'], 'errors');
    api('POST', 'employees', employeePayload(['registration' => 'C', 'branch_id' => $this->x->id, 'cost_center_id' => $global->id]), $this->token)->assertCreated();
    $id = api('POST', 'employees', employeePayload(['registration' => 'D', 'branch_id' => $this->x->id, 'cost_center_id' => $ccX->id]), $this->token)->assertCreated()->json('data.id');

    api('PATCH', "employees/{$id}", ['branch_id' => $this->y->id], $this->token)->assertStatus(422)->assertJsonValidationErrors(['cost_center_id'], 'errors');
    api('PATCH', "employees/{$id}", ['branch_id' => $this->y->id, 'cost_center_id' => $ccY->id], $this->token)->assertOk()->assertJsonPath('data.cost_center.id', $ccY->id);
});

it('centro de custo: trocar a filial com colaboradores de outra filial → 422; para a mesma filial ou sem filial passa', function () {
    $cc = CostCenter::factory()->global()->create();
    Employee::factory()->create(['branch_id' => $this->x->id, 'cost_center_id' => $cc->id]);

    api('PATCH', "cost-centers/{$cc->id}", ['branch_id' => $this->y->id], $this->token)
        ->assertStatus(422)->assertJsonPath('errors.branch_id.0', __('api.cost_center_employees_other_branch'));
    api('PATCH', "cost-centers/{$cc->id}", ['branch_id' => $this->x->id], $this->token)->assertOk();
    api('PATCH', "cost-centers/{$cc->id}", ['branch_id' => null], $this->token)->assertOk();
});

it('user_id: já vinculado, excluído ou de outra filial (não-admin) → 422; admin de qualquer filial é aceito', function () {
    $linked = userWithRole(Role::Operator, $this->x);
    Employee::factory()->create(['branch_id' => $this->x->id, 'user_id' => $linked->id]);
    $deleted = userWithRole(Role::Operator, $this->x);
    $deleted->delete();
    $otherBranch = userWithRole(Role::Operator, $this->y);
    $admin = userWithRole(Role::Admin);

    api('POST', 'employees', employeePayload(['registration' => 'A', 'branch_id' => $this->x->id, 'user_id' => $linked->id]), $this->token)
        ->assertStatus(422)->assertJsonPath('errors.user_id.0', __('api.employee_user_taken'));
    api('POST', 'employees', employeePayload(['registration' => 'B', 'branch_id' => $this->x->id, 'user_id' => $deleted->id]), $this->token)->assertStatus(422)->assertJsonValidationErrors(['user_id'], 'errors');
    api('POST', 'employees', employeePayload(['registration' => 'C', 'branch_id' => $this->x->id, 'user_id' => $otherBranch->id]), $this->token)
        ->assertStatus(422)->assertJsonPath('errors.user_id.0', __('api.employee_user_branch_mismatch'));
    api('POST', 'employees', employeePayload(['registration' => 'D', 'branch_id' => $this->y->id, 'user_id' => $admin->id]), $this->token)->assertCreated();
});

it('consistência nos dois sentidos: trocar a filial do colaborador vinculado, ou do usuário, ou rebaixar admin → 422', function () {
    $operator = userWithRole(Role::Operator, $this->x);
    $employee = Employee::factory()->create(['branch_id' => $this->x->id, 'user_id' => $operator->id]);

    api('PATCH', "employees/{$employee->id}", ['branch_id' => $this->y->id], $this->token)
        ->assertStatus(422)->assertJsonPath('errors.branch_id.0', __('api.employee_user_branch_mismatch'));
    api('PATCH', "users/{$operator->id}", ['branch_id' => $this->y->id], $this->token)
        ->assertStatus(422)->assertJsonPath('errors.branch_id.0', __('api.user_employee_branch_mismatch'));

    // promovido a admin: a filial deixa de importar
    api('PATCH', "users/{$operator->id}", ['role' => 'admin', 'branch_id' => null], $this->token)->assertOk();
    api('PATCH', "employees/{$employee->id}", ['branch_id' => $this->y->id], $this->token)->assertOk();
    // rebaixar de admin: a filial tem que ser a do colaborador (Y)
    api('PATCH', "users/{$operator->id}", ['role' => 'leader', 'branch_id' => $this->x->id], $this->token)->assertStatus(422)->assertJsonValidationErrors(['branch_id'], 'errors');
    api('PATCH', "users/{$operator->id}", ['role' => 'leader', 'branch_id' => $this->y->id], $this->token)->assertOk();
    // desvincular libera a troca
    api('PATCH', "employees/{$employee->id}", ['user_id' => null], $this->token)->assertOk()->assertJsonPath('data.user', null);
    api('PATCH', "users/{$operator->id}", ['branch_id' => $this->x->id], $this->token)->assertOk();
});

it('criar/alterar trava filial, centro de custo e usuário vinculado (FOR SHARE)', function () {
    $cc = CostCenter::factory()->create(['branch_id' => $this->x->id]);
    $user = userWithRole(Role::Operator, $this->x);

    DB::enableQueryLog();
    $id = api('POST', 'employees', employeePayload(['branch_id' => $this->x->id, 'cost_center_id' => $cc->id, 'user_id' => $user->id]), $this->token)->assertCreated()->json('data.id');
    $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    expect($queries)
        ->toContain('select "id" from "branches" where "branches"."id" = ? and "branches"."deleted_at" is null limit 1 for share')
        ->toContain('select "id", "branch_id" from "cost_centers" where "cost_centers"."id" = ? and "cost_centers"."deleted_at" is null limit 1 for share')
        ->toContain('select "id", "role", "branch_id" from "users" where "users"."id" = ? and "users"."deleted_at" is null limit 1 for share');

    DB::flushQueryLog();
    api('PATCH', "employees/{$id}", ['branch_id' => $this->x->id], $this->token)->assertOk();
    expect(collect(DB::getQueryLog())->pluck('query')->implode("\n"))->toContain('from "branches"')->toContain('for share');
});

it('lista: filtros job_type/branch_id/is_active, q em name e registration, sort por whitelist (padrão name)', function () {
    Employee::factory()->driver()->create(['registration' => 'M-2', 'name' => 'Bruno', 'branch_id' => $this->x->id, 'hired_at' => '2020-01-01']);
    Employee::factory()->mechanic()->create(['registration' => 'M-1', 'name' => 'Carla', 'branch_id' => $this->y->id, 'hired_at' => '2019-01-01']);
    Employee::factory()->inactive()->create(['registration' => 'M-3', 'name' => 'Ana', 'branch_id' => $this->x->id]);

    $names = fn (string $query) => api('GET', "employees{$query}", token: $this->token)->assertOk()->json('data.*.name');

    expect($names(''))->toBe(['Ana', 'Bruno', 'Carla']);
    expect($names('?job_type=driver'))->toBe(['Bruno']);
    expect($names("?branch_id={$this->y->id}"))->toBe(['Carla']);
    expect($names('?is_active=0'))->toBe(['Ana']);
    expect($names('?q=m-1'))->toBe(['Carla']);
    expect($names('?q=brun'))->toBe(['Bruno']);
    expect($names('?sort=registration'))->toBe(['Carla', 'Bruno', 'Ana']);
    expect($names('?sort=-job_type'))->toBe(['Carla', 'Ana', 'Bruno']); // mechanic, leader, driver
    expect($names('?sort=hired_at'))->toBe(['Carla', 'Bruno', 'Ana']); // null por último (asc)

    foreach (['?sort=created_at', '?sort=branch_id', '?job_type=pilot', '?per_page=101'] as $query) {
        api('GET', "employees{$query}", token: $this->token)->assertStatus(422);
    }
});

it('escopo: L vê só a própria filial (outra → 404); M e O → 403; só A escreve', function () {
    $inX = Employee::factory()->create(['name' => 'Em X', 'branch_id' => $this->x->id]);
    $inY = Employee::factory()->create(['name' => 'Em Y', 'branch_id' => $this->y->id]);

    $leader = bearer(userWithRole(Role::Leader, $this->x));
    expect(api('GET', 'employees', token: $leader)->assertOk()->json('data.*.name'))->toBe(['Em X']);
    expect(api('GET', "employees?branch_id={$this->y->id}", token: $leader)->json('data'))->toBe([]);
    api('GET', "employees/{$inX->id}", token: $leader)->assertOk();
    api('GET', "employees/{$inY->id}", token: $leader)->assertNotFound();
    api('PATCH', "employees/{$inX->id}", ['name' => 'X'], $leader)->assertForbidden();
    api('POST', 'employees', employeePayload(['branch_id' => $this->x->id]), $leader)->assertForbidden();

    foreach ([Role::Mechanic, Role::Operator] as $role) {
        $token = bearer(userWithRole($role, $this->x));
        api('GET', 'employees', token: $token)->assertForbidden();
        api('GET', "employees/{$inX->id}", token: $token)->assertForbidden();
    }
});

it('409 no pai: filial, centro de custo e usuário com colaborador não excluído → dependents contém employees', function () {
    $cc = CostCenter::factory()->global()->create();
    $user = userWithRole(Role::Admin);
    $employee = Employee::factory()->create(['branch_id' => $this->y->id, 'cost_center_id' => $cc->id, 'user_id' => $user->id]);

    api('DELETE', "branches/{$this->y->id}", token: $this->token)->assertStatus(409)->assertJsonPath('errors.dependents', ['employees']);
    api('DELETE', "cost-centers/{$cc->id}", token: $this->token)->assertStatus(409)->assertJsonPath('errors.dependents', ['employees']);
    api('DELETE', "users/{$user->id}", token: $this->token)->assertStatus(409)->assertJsonPath('errors.dependents', ['employees']);

    // colaborador excluído não conta
    $employee->delete();
    api('DELETE', "users/{$user->id}", token: $this->token)->assertOk();
    api('DELETE', "cost-centers/{$cc->id}", token: $this->token)->assertOk();
    api('DELETE', "branches/{$this->y->id}", token: $this->token)->assertOk();
});

it('dependentes do colaborador: sem equipamento sob a responsabilidade dele, a exclusão passa (o 409 está no EquipmentsTest)', function () {
    $employee = Employee::factory()->create(['branch_id' => $this->x->id]);

    expect($employee->activeDependents())->toBe([]);
    api('DELETE', "employees/{$employee->id}", token: $this->token)->assertOk();
    expect(employeeLogs($employee->id))->toBe(['created', 'deleted']);
});

it('restore: 200 + audit; 409 se não excluído, matrícula reutilizada, usuário excluído ou já vinculado, filial ou centro de custo excluído', function () {
    $cc = CostCenter::factory()->create(['branch_id' => $this->x->id]);
    $user = userWithRole(Role::Operator, $this->x);
    $employee = Employee::factory()->create(['registration' => 'MAT-9', 'branch_id' => $this->x->id, 'cost_center_id' => $cc->id, 'user_id' => $user->id]);
    $restore = fn () => api('POST', "employees/{$employee->id}/restore", token: $this->token);

    $restore()->assertStatus(409);
    $employee->delete();
    $restore()->assertOk()->assertJsonPath('data.deleted_at', null);
    expect(employeeLogs($employee->id))->toBe(['created', 'deleted', 'restored']);

    // matrícula reutilizada
    $employee->delete();
    $reuse = Employee::factory()->create(['registration' => 'MAT-9', 'branch_id' => $this->x->id]);
    $restore()->assertStatus(409)->assertJsonPath('errors.registration.0', __('api.restore_registration_taken'));
    $reuse->delete();

    // usuário já vinculado a outro colaborador
    $other = Employee::factory()->create(['branch_id' => $this->x->id, 'user_id' => $user->id]);
    $restore()->assertStatus(409)->assertJsonPath('message', __('api.restore_user_unavailable'));
    $other->delete();

    // usuário excluído
    $user->delete();
    $restore()->assertStatus(409)->assertJsonPath('message', __('api.restore_user_unavailable'));
    $user->restore();

    // centro de custo excluído
    $cc->delete();
    $restore()->assertStatus(409)->assertJsonPath('message', __('api.restore_cost_center_deleted'));
    $cc->restore();

    // filial excluída (sem dependentes ativos)
    $user->delete();
    $cc->delete();
    $this->x->delete();
    $restore()->assertStatus(409)->assertJsonPath('message', __('api.restore_branch_deleted'));
});

it('/auth/me devolve o colaborador vinculado {id, registration, name, job_type, branch_id} ou null', function () {
    $user = userWithRole(Role::Mechanic, $this->x);
    $token = bearer($user);
    api('GET', 'auth/me', token: $token)->assertOk()->assertJsonPath('data.employee', null);

    $employee = Employee::factory()->mechanic()->create(['registration' => 'MEC-1', 'name' => 'Mecânico', 'branch_id' => $this->x->id, 'user_id' => $user->id]);
    api('GET', 'auth/me', token: $token)->assertOk()->assertJsonPath('data.employee', [
        'id' => $employee->id, 'registration' => 'MEC-1', 'name' => 'Mecânico', 'job_type' => 'mechanic', 'branch_id' => $this->x->id,
    ]);

    $employee->delete();
    api('GET', 'auth/me', token: $token)->assertOk()->assertJsonPath('data.employee', null);
});

it('/meta/enums devolve job_types e cnh_categories', function () {
    api('GET', 'meta/enums', token: $this->token)->assertOk()
        ->assertJsonPath('data.enums.job_types', ['driver', 'mechanic', 'leader', 'admin_staff'])
        ->assertJsonPath('data.enums.cnh_categories', ['A', 'B', 'C', 'D', 'E', 'AB', 'AC', 'AD', 'AE']);
});

it('CHECKs no banco: job_type, cnh_category e hourly_cost negativo são rejeitados no SQL', function (array $values) {
    $insert = fn () => DB::table('employees')->insert(['registration' => 'SQL', 'name' => 'SQL', 'job_type' => 'leader', 'branch_id' => $this->x->id, ...$values]);

    expect(fn () => DB::transaction($insert))->toThrow(QueryException::class);
})->with([
    'job_type' => [['job_type' => 'pilot']],
    'cnh_category' => [['cnh_category' => 'F']],
    'hourly_cost' => [['hourly_cost' => -1]],
]);

it('auditoria: updated grava só os campos alterados (old/new)', function () {
    $employee = Employee::factory()->create(['name' => 'Antes', 'branch_id' => $this->x->id]);

    api('PATCH', "employees/{$employee->id}", ['name' => 'Depois'], $this->token)->assertOk();

    $log = AuditLog::query()->where('auditable_type', (new Employee)->getMorphClass())->where('auditable_id', $employee->id)->where('action', 'updated')->sole();
    expect($log->old_values)->toBe(['name' => 'Antes'])->and($log->new_values)->toBe(['name' => 'Depois']);
});
