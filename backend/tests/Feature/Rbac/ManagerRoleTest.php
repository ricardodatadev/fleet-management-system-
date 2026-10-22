<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\EquipmentFamily;
use App\Models\Setting;
use App\Models\User;
use App\Policies\ResourcePolicy;
use App\Support\Rbac\EffectivePermissions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
 * F2-01 — perfil Gestor/PCM (`manager`, D18) e escrita com escopo de filial (seção E e E.1 da spec da
 * Fase 2A). A matriz por perfil e por rota está no PermissionMatrixTest; aqui ficam as regras por recurso.
 */

const DENIED_MESSAGE = 'Sem permissão para gravar fora da sua filial.';

beforeEach(function () {
    $this->own = Branch::factory()->create(['code' => 'PROPRIA']);
    $this->other = Branch::factory()->create(['code' => 'OUTRA']);
    $this->manager = userWithRole(Role::Manager, $this->own);
    $this->token = bearer($this->manager);
});

/** Usuários sem escopo de filial: passam em todas as regras de escrita do E.1. */
function unscopedWriters(): array
{
    return [
        'admin' => [fn () => User::factory()->admin()->create()],
        'gestor sem filial' => [fn () => User::factory()->manager()->create()],
    ];
}

function assertBranchDenied($response, bool $onBranchId = true): void
{
    $response->assertStatus(403)->assertJsonPath('status', 'error')->assertJsonPath('message', DENIED_MESSAGE);
    expect($response->json('errors'))->toBe($onBranchId ? ['branch_id' => [DENIED_MESSAGE]] : null);
}

describe('perfil e filial (D18)', function () {
    it('o CHECK do banco aceita manager com e sem filial e continua exigindo filial dos demais perfis', function () {
        expect(User::factory()->manager()->create()->branch_id)->toBeNull();

        expect(fn () => DB::table('users')->where('id', User::factory()->role(Role::Leader)->create()->id)->update(['branch_id' => null]))
            ->toThrow(QueryException::class);
        expect(fn () => DB::table('users')->where('id', $this->manager->id)->update(['role' => 'gestor']))
            ->toThrow(QueryException::class);
    });

    it('POST /users: gestor sem filial → 201; líder sem filial → 422 em branch_id', function () {
        $admin = bearer(User::factory()->admin()->create());
        $payload = fn (string $role, string $username) => ['name' => 'Novo', 'username' => $username, 'email' => "{$username}@example.com", 'password' => 'NovaSenha2026', 'role' => $role];

        api('POST', 'users', $payload('manager', 'gestor.novo'), $admin)->assertCreated()
            ->assertJsonPath('data.role', 'manager')->assertJsonPath('data.branch', null);
        api('POST', 'users', $payload('leader', 'lider.novo'), $admin)->assertStatus(422)->assertJsonValidationErrors('branch_id', 'errors');
    });

    it('PUT /users: tirar a filial do gestor é aceito; do líder, não', function () {
        $admin = bearer(User::factory()->admin()->create());
        $leader = userWithRole(Role::Leader, $this->own);

        api('PUT', "users/{$this->manager->id}", ['branch_id' => null], $admin)->assertOk()->assertJsonPath('data.branch', null);
        api('PUT', "users/{$leader->id}", ['branch_id' => null], $admin)->assertStatus(422)->assertJsonValidationErrors('branch_id', 'errors');
    });

    it('vínculo com colaborador: gestor sem filial vale para qualquer filial; gestor com filial, só a dele', function () {
        $admin = bearer(User::factory()->admin()->create());
        $unscoped = User::factory()->manager()->create();
        $base = ['registration' => 'MAT-G1', 'name' => 'Gestor', 'job_type' => 'leader', 'branch_id' => $this->other->id];

        api('POST', 'employees', [...$base, 'user_id' => $unscoped->id], $admin)->assertCreated();
        api('POST', 'employees', [...$base, 'registration' => 'MAT-G2', 'user_id' => $this->manager->id], $admin)
            ->assertStatus(422)->assertJsonValidationErrors('user_id', 'errors');
    });

    it('/meta/enums traz o perfil manager', function () {
        expect(api('GET', 'meta/enums', token: $this->token)->json('data.enums.roles'))->toBe(['operator', 'mechanic', 'leader', 'manager', 'admin']);
    });
});

describe('escopo de leitura', function () {
    it('gestor com filial vê só a própria filial nos recursos BranchScoped (centros de custo: + os sem filial)', function () {
        $mine = Equipment::factory()->create(['branch_id' => $this->own->id]);
        Equipment::factory()->create(['branch_id' => $this->other->id]);
        $myEmployee = Employee::factory()->create(['branch_id' => $this->own->id]);
        $otherEmployee = Employee::factory()->create(['branch_id' => $this->other->id]);
        $myCc = CostCenter::factory()->create(['branch_id' => $this->own->id]);
        $otherCc = CostCenter::factory()->create(['branch_id' => $this->other->id]);
        $globalCc = CostCenter::factory()->global()->create();

        expect(collect(api('GET', 'equipments?per_page=100', token: $this->token)->json('data'))->pluck('branch.id')->unique()->all())->toBe([$this->own->id]);
        expect(collect(api('GET', 'equipments?per_page=100', token: $this->token)->json('data'))->pluck('id'))->toContain($mine->id);
        expect(collect(api('GET', 'employees', token: $this->token)->json('data'))->pluck('id')->all())->toBe([$myEmployee->id]);
        expect(collect(api('GET', 'cost-centers?per_page=100', token: $this->token)->json('data'))->pluck('id'))
            ->toContain($myCc->id, $globalCc->id)->not->toContain($otherCc->id);
        api('GET', "employees/{$otherEmployee->id}", token: $this->token)->assertNotFound();
        api('GET', "cost-centers/{$otherCc->id}", token: $this->token)->assertNotFound();
    });

    it('gestor sem filial vê todas as filiais', function () {
        $token = bearer(User::factory()->manager()->create());
        Employee::factory()->create(['branch_id' => $this->own->id]);
        Employee::factory()->create(['branch_id' => $this->other->id]);

        expect(collect(api('GET', 'employees', token: $token)->json('data'))->pluck('branch.id')->sort()->values()->all())
            ->toBe([$this->own->id, $this->other->id]);
    });

    it('gestor (com ou sem filial) não acessa users.*, audit.view nem branches.manage', function (bool $withBranch) {
        $token = $withBranch ? $this->token : bearer(User::factory()->manager()->create());

        api('GET', 'users', token: $token)->assertForbidden();
        api('POST', 'users', ['name' => 'X'], $token)->assertForbidden();
        api('GET', 'audit-logs', token: $token)->assertForbidden();
        api('GET', 'audit-logs/'.AuditLog::query()->value('id'), token: $token)->assertForbidden();
        api('POST', 'branches', ['code' => 'NOVA', 'name' => 'Nova', 'type' => 'filial'], $token)->assertForbidden();
        api('PUT', "branches/{$this->own->id}", ['name' => 'X'], $token)->assertForbidden();
        api('GET', 'branches', token: $token)->assertOk();
    })->with(['com filial' => true, 'sem filial' => false]);
});

describe('escrita do gestor com filial nos cadastros (E.1)', function () {
    it('equipments: cria na própria filial (201) e não cria em outra nem move para outra (403 com errors.branch_id)', function () {
        $family = EquipmentFamily::factory()->create();
        $cc = CostCenter::factory()->global()->create();
        $payload = fn (string $code, int $branchId) => ['code' => $code, 'name' => 'Novo', 'family_id' => $family->id, 'branch_id' => $branchId, 'cost_center_id' => $cc->id];

        api('POST', 'equipments', $payload('EQ-PROPRIO', $this->own->id), $this->token)->assertCreated();
        assertBranchDenied(api('POST', 'equipments', $payload('EQ-OUTRO', $this->other->id), $this->token));

        $equipment = Equipment::factory()->create(['branch_id' => $this->own->id]);
        assertBranchDenied(api('PATCH', "equipments/{$equipment->id}", ['branch_id' => $this->other->id], $this->token));
        api('PUT', "equipments/{$equipment->id}", ['name' => 'Renomeado'], $this->token)->assertOk(); // sem branch_id: a atual
        expect($equipment->refresh()->branch_id)->toBe($this->own->id);
    });

    it('employees: cria na própria filial (201) e não cria em outra nem move para outra (403 com errors.branch_id)', function () {
        $payload = fn (string $registration, int $branchId) => ['registration' => $registration, 'name' => 'Novo', 'job_type' => 'leader', 'branch_id' => $branchId];

        api('POST', 'employees', $payload('MAT-PROPRIA', $this->own->id), $this->token)->assertCreated();
        assertBranchDenied(api('POST', 'employees', $payload('MAT-OUTRA', $this->other->id), $this->token));

        $employee = Employee::factory()->create(['branch_id' => $this->own->id]);
        assertBranchDenied(api('PUT', "employees/{$employee->id}", ['branch_id' => $this->other->id], $this->token));
        expect($employee->refresh()->branch_id)->toBe($this->own->id);
    });

    it('403 tem precedência sobre o 422 de consistência da Fase 1', function () {
        // centro de custo de uma terceira filial: para o admin é 422 (consistência); para o gestor, 403
        $third = CostCenter::factory()->create();
        $family = EquipmentFamily::factory()->create();
        $equipment = ['code' => 'EQ-X', 'name' => 'X', 'family_id' => $family->id, 'branch_id' => $this->other->id, 'cost_center_id' => $third->id];
        $employee = ['registration' => 'MAT-X', 'name' => 'X', 'job_type' => 'leader', 'branch_id' => $this->other->id, 'cost_center_id' => $third->id];
        $admin = bearer(User::factory()->admin()->create());

        api('POST', 'equipments', $equipment, $admin)->assertStatus(422);
        api('POST', 'employees', $employee, $admin)->assertStatus(422);
        assertBranchDenied(api('POST', 'equipments', $equipment, $this->token));
        assertBranchDenied(api('POST', 'employees', $employee, $this->token));
    });

    it('cost_centers: só os da própria filial; sem filial ou outra filial → 403', function () {
        api('POST', 'cost-centers', ['code' => 'CC-PROPRIO', 'name' => 'Próprio', 'branch_id' => $this->own->id], $this->token)->assertCreated();
        assertBranchDenied(api('POST', 'cost-centers', ['code' => 'CC-GLOBAL', 'name' => 'Sem filial'], $this->token));
        assertBranchDenied(api('POST', 'cost-centers', ['code' => 'CC-GLOBAL2', 'name' => 'Sem filial', 'branch_id' => null], $this->token));
        assertBranchDenied(api('POST', 'cost-centers', ['code' => 'CC-OUTRO', 'name' => 'Outro', 'branch_id' => $this->other->id], $this->token));

        $mine = CostCenter::factory()->create(['branch_id' => $this->own->id]);
        api('PUT', "cost-centers/{$mine->id}", ['name' => 'Renomeado'], $this->token)->assertOk();
        assertBranchDenied(api('PATCH', "cost-centers/{$mine->id}", ['branch_id' => null], $this->token));
        assertBranchDenied(api('PATCH', "cost-centers/{$mine->id}", ['branch_id' => $this->other->id], $this->token));
        api('DELETE', "cost-centers/{$mine->id}", token: $this->token)->assertOk();
        api('POST', "cost-centers/{$mine->id}/restore", token: $this->token)->assertOk();
        expect($mine->refresh()->branch_id)->toBe($this->own->id);
    });

    it('cost_centers sem filial: vê, mas não edita, exclui nem restaura (403 sem errors.branch_id)', function () {
        $global = CostCenter::factory()->global()->create();
        $trashed = CostCenter::factory()->global()->create();
        $trashed->delete();

        api('GET', "cost-centers/{$global->id}", token: $this->token)->assertOk();
        assertBranchDenied(api('PUT', "cost-centers/{$global->id}", ['name' => 'Renomeado'], $this->token), onBranchId: false);
        assertBranchDenied(api('PATCH', "cost-centers/{$global->id}", ['branch_id' => $this->own->id], $this->token), onBranchId: false);
        assertBranchDenied(api('DELETE', "cost-centers/{$global->id}", token: $this->token), onBranchId: false);
        assertBranchDenied(api('POST', "cost-centers/{$trashed->id}/restore", token: $this->token), onBranchId: false);
        expect($global->refresh()->name)->not->toBe('Renomeado')->and($global->trashed())->toBeFalse();
    });

    it('cost_centers sem filial podem ser vinculados a equipamento e colaborador da própria filial', function () {
        $global = CostCenter::factory()->global()->create();
        $family = EquipmentFamily::factory()->create();

        api('POST', 'equipments', ['code' => 'EQ-CC', 'name' => 'Com CC global', 'family_id' => $family->id, 'branch_id' => $this->own->id, 'cost_center_id' => $global->id], $this->token)->assertCreated();
        api('POST', 'employees', ['registration' => 'MAT-CC', 'name' => 'Com CC global', 'job_type' => 'leader', 'branch_id' => $this->own->id, 'cost_center_id' => $global->id], $this->token)->assertCreated();
        $employee = Employee::factory()->create(['branch_id' => $this->own->id]);
        api('PUT', "employees/{$employee->id}", ['cost_center_id' => $global->id], $this->token)->assertOk();
    });

    it('equipment_families: leitura 200 e escrita 403 (catálogo global)', function () {
        $family = EquipmentFamily::factory()->create();
        $trashed = EquipmentFamily::factory()->create();
        $trashed->delete();

        api('GET', 'equipment-families', token: $this->token)->assertOk();
        api('GET', "equipment-families/{$family->id}", token: $this->token)->assertOk();
        api('POST', 'equipment-families', ['code' => 'FAM-G', 'name' => 'Nova', 'category' => 'truck'], $this->token)->assertForbidden();
        api('PUT', "equipment-families/{$family->id}", ['name' => 'Renomeada'], $this->token)->assertForbidden();
        api('DELETE', "equipment-families/{$family->id}", token: $this->token)->assertForbidden();
        api('POST', "equipment-families/{$trashed->id}/restore", token: $this->token)->assertForbidden();
        expect($family->refresh()->name)->not->toBe('Renomeada');
    });

    it('admin e gestor sem filial passam em todos', function (Closure $makeUser) {
        $token = bearer($makeUser());
        $family = EquipmentFamily::factory()->create();
        $global = CostCenter::factory()->global()->create();

        api('POST', 'equipments', ['code' => 'EQ-L', 'name' => 'Livre', 'family_id' => $family->id, 'branch_id' => $this->other->id, 'cost_center_id' => $global->id], $token)->assertCreated();
        api('POST', 'employees', ['registration' => 'MAT-L', 'name' => 'Livre', 'job_type' => 'leader', 'branch_id' => $this->other->id], $token)->assertCreated();
        api('POST', 'cost-centers', ['code' => 'CC-L', 'name' => 'Sem filial'], $token)->assertCreated();
        api('PUT', "cost-centers/{$global->id}", ['name' => 'Renomeado'], $token)->assertOk();
        api('POST', 'equipment-families', ['code' => 'FAM-L', 'name' => 'Livre', 'category' => 'truck'], $token)->assertCreated();
        api('PUT', "equipment-families/{$family->id}", ['name' => 'Renomeada'], $token)->assertOk();
        $employee = Employee::factory()->create(['branch_id' => $this->own->id]);
        api('PATCH', "employees/{$employee->id}", ['branch_id' => $this->other->id], $token)->assertOk();
    })->with(unscopedWriters());
});

describe('settings.* do gestor com filial (seção E, ajuste 7)', function () {
    it('grava e remove override da própria filial', function () {
        api('PUT', 'settings/warranty.alert_mode', ['scope_type' => 'branch', 'scope_id' => $this->own->id, 'value' => 'hard_block'], $this->token)->assertOk();
        api('DELETE', "settings/warranty.alert_mode?scope_type=branch&scope_id={$this->own->id}", token: $this->token)->assertOk();
        expect(Setting::query()->where('scope_type', 'branch')->exists())->toBeFalse();
    });

    it('global, família e outra filial → 403 no PUT e no DELETE', function () {
        $family = EquipmentFamily::factory()->create();
        foreach ([['branch', $this->other->id], ['family', $family->id]] as [$type, $id]) {
            Setting::query()->create(['key' => 'warranty.alert_mode', 'scope_type' => $type, 'scope_id' => $id, 'value' => 'hard_block']);
        }

        foreach ([['global', null], ['family', $family->id], ['branch', $this->other->id]] as [$type, $id]) {
            assertBranchDenied(api('PUT', 'settings/warranty.alert_mode', ['scope_type' => $type, 'scope_id' => $id, 'value' => 'alert_only'], $this->token), onBranchId: false);
            $query = $id === null ? "scope_type={$type}" : "scope_type={$type}&scope_id={$id}";
            assertBranchDenied(api('DELETE', "settings/warranty.alert_mode?{$query}", token: $this->token), onBranchId: false);
        }
        expect(Setting::query()->where('scope_type', '!=', 'global')->pluck('value')->unique()->all())->toBe(['hard_block']);
    });

    it('403 vem antes da validação (valor inválido em escopo proibido)', function () {
        assertBranchDenied(api('PUT', 'settings/warranty.alert_mode', ['scope_type' => 'global', 'scope_id' => null, 'value' => 'nao-existe'], $this->token), onBranchId: false);
        api('PUT', 'settings/warranty.alert_mode', ['scope_type' => 'branch', 'scope_id' => $this->own->id, 'value' => 'nao-existe'], $this->token)->assertStatus(422);
    });

    it('leitura: só a própria filial e as famílias; scope_id de outra filial → 403', function () {
        Setting::query()->create(['key' => 'warranty.alert_mode', 'scope_type' => 'branch', 'scope_id' => $this->own->id, 'value' => 'hard_block']);
        Setting::query()->create(['key' => 'warranty.alert_mode', 'scope_type' => 'branch', 'scope_id' => $this->other->id, 'value' => 'hard_block']);

        $branchIds = collect(api('GET', 'settings?scope_type=branch', token: $this->token)->json('data'))->pluck('scope_id')->all();
        expect($branchIds)->toBe([$this->own->id]);
        api('GET', "settings?scope_type=branch&scope_id={$this->other->id}", token: $this->token)->assertForbidden();
        api('GET', "settings/effective?key=warranty.alert_mode&branch_id={$this->other->id}", token: $this->token)->assertForbidden();
        api('GET', "settings/effective?key=warranty.alert_mode&branch_id={$this->own->id}", token: $this->token)->assertOk();
        api('GET', 'settings/definitions', token: $this->token)->assertOk();
    });

    it('admin e gestor sem filial gravam global e overrides de qualquer filial', function (Closure $makeUser) {
        $token = bearer($makeUser());

        api('PUT', 'settings/warranty.alert_mode', ['scope_type' => 'global', 'scope_id' => null, 'value' => 'hard_block'], $token)->assertOk();
        api('PUT', 'settings/warranty.alert_mode', ['scope_type' => 'branch', 'scope_id' => $this->other->id, 'value' => 'hard_block'], $token)->assertOk();
        api('DELETE', "settings/warranty.alert_mode?scope_type=branch&scope_id={$this->other->id}", token: $token)->assertOk();
    })->with(unscopedWriters());
});

describe('ResourcePolicy::create sem rascunho', function () {
    it('nega (branch_scope) a quem tem escopo de filial e libera a quem não tem', function (string $class) {
        $scoped = Gate::forUser($this->manager)->inspect('create', $class);
        expect($scoped->denied())->toBeTrue()->and($scoped->code())->toBe(ResourcePolicy::DENY_SCOPE);

        expect(Gate::forUser(User::factory()->manager()->create())->allows('create', $class))->toBeTrue()
            ->and(Gate::forUser(User::factory()->admin()->create())->allows('create', $class))->toBeTrue();
        // com o rascunho na própria filial, o mesmo gestor passa (o controller sempre passa o destino)
        if ($class !== CostCenter::class) {
            expect(Gate::forUser($this->manager)->allows('create', [$class, (new $class)->forceFill(['branch_id' => $this->own->id])]))->toBeTrue();
        }
    })->with([Equipment::class, Employee::class, CostCenter::class]);

    it('controller que esquece o rascunho responde 403 no envelope (rota de teste)', function () {
        Route::middleware(['api', 'auth:sanctum'])->post('api/v1/_t/sem-rascunho', fn () => Gate::authorize('create', Equipment::class) ? 'criado' : 'x');

        assertBranchDenied(api('POST', '_t/sem-rascunho', token: $this->token), onBranchId: false);
        api('POST', '_t/sem-rascunho', token: bearer(User::factory()->manager()->create()))->assertOk();
    });
});

describe('EffectivePermissions', function () {
    it('recorta só o manage dos catálogos globais e só de quem tem escopo de filial', function () {
        expect(EffectivePermissions::for($this->manager))->toBe(array_values(array_diff(config('rbac.roles.manager'), ['equipment_families.manage', 'service_categories.manage'])));
        expect(EffectivePermissions::for(User::factory()->manager()->create()))->toBe(config('rbac.roles.manager'));
        expect(EffectivePermissions::for(User::factory()->admin()->create(['branch_id' => $this->own->id])))->toBe(config('rbac.roles.admin'));
        expect(EffectivePermissions::for(userWithRole(Role::Leader, $this->own)))->toBe(config('rbac.roles.leader'));
    });

    it('canWriteScope: sem escopo sempre; com escopo, só na própria filial e nunca em registro global', function () {
        $unscoped = User::factory()->manager()->create();

        expect(EffectivePermissions::canWriteScope($unscoped, null, true))->toBeTrue()
            ->and(EffectivePermissions::canWriteScope($unscoped, $this->other->id, false))->toBeTrue()
            ->and(EffectivePermissions::canWriteScope($this->manager, $this->own->id, false))->toBeTrue()
            ->and(EffectivePermissions::canWriteScope($this->manager, $this->own->id, true))->toBeFalse()
            ->and(EffectivePermissions::canWriteScope($this->manager, null, false))->toBeFalse()
            ->and(EffectivePermissions::canWriteScope($this->manager, $this->other->id, false))->toBeFalse();
    });
});
