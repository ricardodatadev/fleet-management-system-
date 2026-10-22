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
use App\Support\Rbac\Rbac;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
 * Matriz da seção E transcrita LITERALMENTE (fonte independente de config/rbac.php): Fase 1 + Fase 2A.
 * Colunas: O (operator), M (mechanic), L (leader), G (manager, sem filial), A (admin). 1 = ✔, 0 = ✖.
 * auth.*  → Gate implícito auth.session; *.restore/with_trashed → contidos em cada *.manage (policies).
 * "G com filial" (E.1): a coluna G sem o manage dos catálogos globais (MATRIX_G_BRANCH_DENIED).
 */
const MATRIX_E = [
    'auth.*' => [1, 1, 1, 1, 1],
    'branches.view' => [1, 1, 1, 1, 1],
    'branches.manage' => [0, 0, 0, 0, 1],
    'cost_centers.view' => [0, 0, 1, 1, 1],
    'cost_centers.manage' => [0, 0, 0, 1, 1],
    'equipment_families.view' => [0, 1, 1, 1, 1],
    'equipment_families.manage' => [0, 0, 0, 1, 1],
    'equipments.view' => [1, 1, 1, 1, 1],
    'equipments.manage' => [0, 0, 0, 1, 1],
    'employees.view' => [0, 0, 1, 1, 1],
    'employees.manage' => [0, 0, 0, 1, 1],
    'users.view / users.manage' => [0, 0, 0, 0, 1],
    'settings.view' => [0, 0, 1, 1, 1],
    'settings.manage' => [0, 0, 0, 1, 1],
    'audit.view' => [0, 0, 0, 0, 1],
    // Fase 2A (seção E)
    'work_orders.view' => [1, 1, 1, 1, 1],
    'work_orders.create' => [0, 0, 1, 1, 1],
    'work_orders.plan' => [0, 0, 1, 1, 1],
    'work_orders.execute' => [0, 1, 1, 1, 1],
    'work_orders.labor.manage' => [0, 0, 1, 1, 1],
    'work_orders.parts.fulfill' => [0, 0, 1, 1, 1],
    'work_orders.close' => [0, 0, 1, 1, 1],
    'work_orders.cancel' => [0, 0, 0, 1, 1],
    'work_orders.costs.view' => [0, 0, 1, 1, 1],
    'work_orders.costs.manage' => [0, 0, 0, 1, 1],
    'meter_readings.create' => [1, 1, 1, 1, 1],
    'service_categories.view' => [0, 1, 1, 1, 1],
    'service_categories.manage' => [0, 0, 0, 1, 1],
];

const MATRIX_ROLES = ['operator', 'mechanic', 'leader', 'manager', 'admin'];

/** Linhas que o gestor COM filial perde (catálogos globais, E.1). */
const MATRIX_G_BRANCH_DENIED = ['equipment_families.manage', 'service_categories.manage'];

/**
 * Usuário de cada coluna: admin e `manager` sem filial; `manager_branch` (G com filial) e os demais
 * perfis numa filial nova.
 */
function matrixUser(string $role): User
{
    return match ($role) {
        'admin' => User::factory()->admin()->create(),
        'manager' => User::factory()->manager()->create(),
        'manager_branch' => userWithRole(Role::Manager, Branch::factory()->create()),
        default => userWithRole(Role::from($role), Branch::factory()->create()),
    };
}

/** Permissões (Gates) que representam cada linha da matriz. */
function matrixRowAbilities(string $row): array
{
    return match ($row) {
        'auth.*' => [Rbac::SESSION],
        'users.view / users.manage' => ['users.view', 'users.manage'],
        default => [$row],
    };
}

/** Dataset perfil × linha da matriz (28 linhas × 5 perfis + a variante G com filial). */
function matrixCases(): array
{
    $cases = [];
    foreach (MATRIX_E as $row => $flags) {
        foreach (MATRIX_ROLES as $i => $role) {
            $cases["{$role} · {$row}"] = [$role, $row, (bool) $flags[$i]];
        }
        $cases["manager_branch · {$row}"] = ['manager_branch', $row, (bool) $flags[3] && ! in_array($row, MATRIX_G_BRANCH_DENIED, true)];
    }

    return $cases;
}

it('matriz E: cada perfil × cada linha concede/nega conforme a tabela', function (string $role, string $row, bool $expected) {
    $user = matrixUser($role);

    foreach (matrixRowAbilities($row) as $ability) {
        expect(Gate::forUser($user)->allows($ability))->toBe($expected, "{$role} → {$ability}");
    }
})->with(matrixCases());

it('config/rbac.php é exatamente a matriz E (mesmas permissões, na ordem da tabela)', function () {
    foreach (MATRIX_ROLES as $i => $role) {
        $expected = [];
        foreach (MATRIX_E as $row => $flags) {
            if ($flags[$i] && $row !== 'auth.*') {
                array_push($expected, ...matrixRowAbilities($row));
            }
        }
        expect(config("rbac.roles.{$role}"))->toBe($expected, "perfil {$role}");
    }
});

it('nenhuma permissão fora da matriz e nenhum Gate de permissão sem definição', function () {
    $matrix = collect(array_keys(MATRIX_E))->flatMap(fn ($row) => matrixRowAbilities($row))->unique()->sort()->values()->all();
    $abilities = collect(Rbac::abilities())->sort()->values()->all();

    expect($abilities)->toBe($matrix);
    foreach ($abilities as $ability) {
        expect(Gate::has($ability))->toBeTrue($ability);
    }
});

it('restore/with_trashed seguem *.manage nas policies (Branch, User)', function (string $role) {
    $user = matrixUser($role);
    $branch = Branch::factory()->create();
    $other = User::factory()->create();
    $isAdmin = $role === 'admin';

    foreach ([[$branch, Branch::class], [$other, User::class]] as [$model, $class]) {
        $gate = Gate::forUser($user);
        expect($gate->allows('restore', $model))->toBe($isAdmin)
            ->and($gate->allows('viewTrashed', $class))->toBe($isAdmin)
            ->and($gate->allows('create', $class))->toBe($isAdmin)
            ->and($gate->allows('update', $model))->toBe($isAdmin)
            ->and($gate->allows('delete', $model))->toBe($isAdmin)
            ->and($gate->allows('forceDelete', $model))->toBeFalse();
    }
    expect(Gate::forUser($user)->allows('viewAny', Branch::class))->toBeTrue();
    expect(Gate::forUser($user)->allows('view', $branch))->toBeTrue(); // filiais não têm escopo de filial
    expect(Gate::forUser($user)->allows('viewAny', User::class))->toBe($isAdmin);
})->with([...MATRIX_ROLES, 'manager_branch']);

it('AuditLog: leitura só com audit.view e nenhuma escrita, nem para admin', function (string $role) {
    $user = matrixUser($role);
    $log = AuditLog::query()->firstOrFail();
    $gate = Gate::forUser($user);

    expect($gate->allows('viewAny', AuditLog::class))->toBe($role === 'admin')
        ->and($gate->allows('view', $log))->toBe($role === 'admin')
        ->and($gate->allows('create', AuditLog::class))->toBeFalse()
        ->and($gate->allows('update', $log))->toBeFalse()
        ->and($gate->allows('delete', $log))->toBeFalse();
})->with([...MATRIX_ROLES, 'manager_branch']);

/*
 * Camada 2 — perfil × rota × HTTP esperado, para as rotas existentes.
 * Regra da H ("RBAC em cada cadastro"): cada tarefa acrescenta aqui as linhas das suas rotas.
 * Colunas: os 5 perfis (G = `manager` sem filial) + `manager_branch` (G com filial, E.1).
 * A URI é a da rota; os parâmetros {x} são trocados pelas fixtures de routeFixtures() (5º item: mapa
 * parâmetro → fixture, quando a rota precisa de outra, ex.: restore usa o registro excluído).
 */
function routeMatrix(): array
{
    $all = array_fill_keys([...MATRIX_ROLES, 'manager_branch'], 200);
    $adminOnly = fn (int $ok) => ['operator' => 403, 'mechanic' => 403, 'leader' => 403, 'manager' => 403, 'manager_branch' => 403, 'admin' => $ok];
    // manage herdado pelo gestor (cadastros): G sem filial como o admin; G com filial conforme o escopo do registro
    $managerAndAdmin = fn (int $ok, int $withBranch) => ['operator' => 403, 'mechanic' => 403, 'leader' => 403, 'manager' => $ok, 'manager_branch' => $withBranch, 'admin' => $ok];
    $leaderUp = ['operator' => 403, 'mechanic' => 403, 'leader' => 200, 'manager' => 200, 'manager_branch' => 200, 'admin' => 200];
    $mechanicUp = ['operator' => 403, 'mechanic' => 200, 'leader' => 200, 'manager' => 200, 'manager_branch' => 200, 'admin' => 200];

    return [
        ['GET', 'auth/me', [], $all],
        ['GET', 'meta/enums', [], $all],
        ['PUT', 'auth/password', ['current_password' => UserFactory::PASSWORD, 'password' => 'NovaSenha2026', 'password_confirmation' => 'NovaSenha2026'], $all],
        ['POST', 'auth/logout', [], $all],
        // F1-12 — filiais (branches.view: todos; branches.manage: A)
        ['GET', 'branches', [], $all],
        ['POST', 'branches', ['code' => 'NOVA', 'name' => 'Nova', 'type' => 'filial'], $adminOnly(201)],
        ['GET', 'branches/{branch}', [], $all],
        ['PUT', 'branches/{branch}', ['name' => 'Renomeada'], $adminOnly(200)],
        ['PATCH', 'branches/{branch}', ['name' => 'Renomeada'], $adminOnly(200)],
        ['DELETE', 'branches/{branch}', [], $adminOnly(200)],
        ['POST', 'branches/{branch}/restore', [], $adminOnly(200), ['branch' => 'trashed_branch']],
        // F1-12 — centros de custo (view: L, G, A; manage: G, A). Fixture SEM filial: visível a L e G com
        // filial, mas global para a escrita (E.1) → 403 ao G com filial (o da própria filial está no RBAC por recurso).
        ['GET', 'cost-centers', [], $leaderUp],
        ['POST', 'cost-centers', ['code' => 'CC-NOVO', 'name' => 'Novo'], $managerAndAdmin(201, 403)],
        ['GET', 'cost-centers/{cost_center}', [], $leaderUp],
        ['PUT', 'cost-centers/{cost_center}', ['name' => 'Renomeado'], $managerAndAdmin(200, 403)],
        ['PATCH', 'cost-centers/{cost_center}', ['name' => 'Renomeado'], $managerAndAdmin(200, 403)],
        ['DELETE', 'cost-centers/{cost_center}', [], $managerAndAdmin(200, 403)],
        ['POST', 'cost-centers/{cost_center}/restore', [], $managerAndAdmin(200, 403), ['cost_center' => 'trashed_cost_center']],
        // F1-13 — famílias (view: M, L, G, A; manage: G sem filial e A — catálogo global, E.1)
        ['GET', 'equipment-families', [], $mechanicUp],
        ['POST', 'equipment-families', ['code' => 'FAM-NOVA', 'name' => 'Nova', 'category' => 'truck'], $managerAndAdmin(201, 403)],
        ['GET', 'equipment-families/{equipment_family}', [], $mechanicUp],
        ['PUT', 'equipment-families/{equipment_family}', ['name' => 'Renomeada'], $managerAndAdmin(200, 403)],
        ['PATCH', 'equipment-families/{equipment_family}', ['name' => 'Renomeada'], $managerAndAdmin(200, 403)],
        ['DELETE', 'equipment-families/{equipment_family}', [], $managerAndAdmin(200, 403)],
        ['POST', 'equipment-families/{equipment_family}/restore', [], $managerAndAdmin(200, 403), ['equipment_family' => 'trashed_equipment_family']],
        // F1-14 — colaboradores (view: L, G, A; manage: G, A). Fixtures na filial do usuário; o POST usa
        // {branch} (outra filial) → 403 ao G com filial, e {own_branch} → 201.
        ['GET', 'employees', [], $leaderUp],
        ['POST', 'employees', ['registration' => 'MAT-NOVA', 'name' => 'Novo', 'job_type' => 'leader', 'branch_id' => '{branch}'], $managerAndAdmin(201, 403)],
        ['POST', 'employees', ['registration' => 'MAT-NOVA', 'name' => 'Novo', 'job_type' => 'leader', 'branch_id' => '{own_branch}'], $managerAndAdmin(201, 201)],
        ['GET', 'employees/{employee}', [], $leaderUp],
        ['PUT', 'employees/{employee}', ['name' => 'Renomeado'], $managerAndAdmin(200, 200)],
        ['PATCH', 'employees/{employee}', ['branch_id' => '{branch}'], $managerAndAdmin(200, 403)],
        ['DELETE', 'employees/{employee}', [], $managerAndAdmin(200, 200)],
        ['POST', 'employees/{employee}/restore', [], $managerAndAdmin(200, 200), ['employee' => 'trashed_employee']],
        // F1-15 — equipamentos (view: todos, na própria filial; manage: G, A). Fixture na filial do usuário.
        ['GET', 'equipments', [], $all],
        ['POST', 'equipments', ['code' => 'EQ-NOVO', 'name' => 'Novo', 'family_id' => '{equipment_family}', 'branch_id' => '{branch}', 'cost_center_id' => '{cost_center}'], $managerAndAdmin(201, 403)],
        ['POST', 'equipments', ['code' => 'EQ-NOVO', 'name' => 'Novo', 'family_id' => '{equipment_family}', 'branch_id' => '{own_branch}', 'cost_center_id' => '{cost_center}'], $managerAndAdmin(201, 201)],
        ['GET', 'equipments/{equipment}', [], $all],
        ['PUT', 'equipments/{equipment}', ['name' => 'Renomeado'], $managerAndAdmin(200, 200)],
        ['PATCH', 'equipments/{equipment}', ['branch_id' => '{branch}'], $managerAndAdmin(200, 403)],
        ['DELETE', 'equipments/{equipment}', [], $managerAndAdmin(200, 200)],
        ['POST', 'equipments/{equipment}/restore', [], $managerAndAdmin(200, 200), ['equipment' => 'trashed_equipment']],
        // F1-16 — parâmetros (view: L, G, A; manage: G, A). G com filial só grava override da própria filial
        // (global e família → 403); o escopo de filial completo está no SettingsTest.
        ['GET', 'settings/definitions', [], $leaderUp],
        ['GET', 'settings', [], $leaderUp],
        ['GET', 'settings/effective', ['key' => 'warranty.alert_mode'], $leaderUp],
        ['PUT', 'settings/{key}', ['scope_type' => 'global', 'scope_id' => null, 'value' => 'hard_block'], $managerAndAdmin(200, 403)],
        ['PUT', 'settings/{key}', ['scope_type' => 'branch', 'scope_id' => '{own_branch}', 'value' => 'hard_block'], $managerAndAdmin(200, 200)],
        ['DELETE', 'settings/{key}', [], $managerAndAdmin(200, 403), ['key' => 'setting_override']],
        // F1-11 — usuários (users.view/users.manage: A) e auditoria (audit.view: A, somente leitura)
        ['GET', 'users', [], $adminOnly(200)],
        ['POST', 'users', ['name' => 'Novo', 'username' => 'novo', 'email' => 'novo@example.com', 'password' => 'NovaSenha2026', 'role' => 'admin'], $adminOnly(201)],
        ['GET', 'users/{user}', [], $adminOnly(200)],
        ['PUT', 'users/{user}', ['name' => 'Renomeado'], $adminOnly(200)],
        ['PATCH', 'users/{user}', ['name' => 'Renomeado'], $adminOnly(200)],
        ['DELETE', 'users/{user}', [], $adminOnly(200)],
        ['POST', 'users/{user}/restore', [], $adminOnly(200), ['user' => 'trashed_user']],
        ['GET', 'audit-logs', [], $adminOnly(200)],
        ['GET', 'audit-logs/{audit_log}', [], $adminOnly(200)],
    ];
}

/**
 * Registros usados nas rotas com {parâmetro}; criados por caso (banco limpo a cada teste). Registros com
 * escopo de filial ficam na filial do usuário do caso (ou numa nova, para o admin).
 */
function routeFixtures(User $user): array
{
    $ownBranchId = $user->branch_id ?? Branch::factory()->create()->id;
    $trashedEmployee = Employee::factory()->create(['branch_id' => $ownBranchId]);
    $trashedEmployee->delete();
    $trashedEquipment = Equipment::factory()->create(['branch_id' => $ownBranchId]);
    $trashedEquipment->delete();
    $trashedBranch = Branch::factory()->create();
    $trashedBranch->delete();
    $trashedCostCenter = CostCenter::factory()->global()->create();
    $trashedCostCenter->delete();
    $trashedFamily = EquipmentFamily::factory()->create();
    $trashedFamily->delete();
    $trashedUser = User::factory()->create();
    $trashedUser->delete();

    return [
        'branch' => Branch::factory()->create()->id, // sem dependentes: pode ser excluída (outra filial)
        'own_branch' => $ownBranchId,
        'trashed_branch' => $trashedBranch->id,
        'cost_center' => CostCenter::factory()->global()->create()->id,
        'trashed_cost_center' => $trashedCostCenter->id,
        'equipment_family' => EquipmentFamily::factory()->create()->id,
        'trashed_equipment_family' => $trashedFamily->id,
        'user' => User::factory()->create()->id, // operador: pode ser excluído
        'trashed_user' => $trashedUser->id,
        'employee' => Employee::factory()->create(['branch_id' => $ownBranchId])->id,
        'trashed_employee' => $trashedEmployee->id,
        'equipment' => Equipment::factory()->create(['branch_id' => $ownBranchId])->id,
        'trashed_equipment' => $trashedEquipment->id,
        'key' => 'warranty.alert_mode',
        // override de família existente: a rota de exclusão leva escopo na query
        'setting_override' => 'warranty.alert_mode?scope_type=family&scope_id='.Setting::query()->create([
            'key' => 'warranty.alert_mode', 'scope_type' => 'family', 'scope_id' => EquipmentFamily::factory()->create()->id, 'value' => 'hard_block',
        ])->scope_id,
        'audit_log' => AuditLog::query()->value('id'),
    ];
}

function routeMatrixCases(): array
{
    $cases = [];
    foreach (routeMatrix() as $i => $row) {
        [$method, $uri, $payload, $expected] = $row;
        foreach ($expected as $role => $status) {
            $cases["#{$i} {$role} {$method} /{$uri} → {$status}"] = [$role, $method, $uri, $payload, $status, $row[4] ?? []];
        }
    }

    return $cases;
}

it('rotas: perfil × rota × HTTP esperado', function (string $role, string $method, string $uri, array $payload, int $status, array $params) {
    $user = matrixUser($role);
    $fixtures = routeFixtures($user);
    $uri = preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) $fixtures[$params[$m[1]] ?? $m[1]], $uri);
    // payload pode referenciar fixtures ('{branch}' → id)
    $payload = array_map(fn ($v) => is_string($v) && preg_match('/^\{(\w+)\}$/', $v, $m) ? $fixtures[$m[1]] : $v, $payload);

    api($method, $uri, $payload, bearer($user))->assertStatus($status);
})->with(routeMatrixCases());

it('o dataset por rota cobre todas as rotas protegidas de api/v1', function () {
    $covered = collect(routeMatrix())->map(fn ($r) => $r[0].' '.$r[1])->unique()->sort()->values()->all();
    $protected = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/') && ! in_array($r->uri(), RBAC_PUBLIC_ROUTES, true))
        ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS'], true))->map(fn ($m) => $m.' '.substr($r->uri(), 7)))
        ->sort()->values()->all();

    expect($covered)->toBe($protected);
});

it('permissions de /auth/me == config/rbac.php do perfil', function (string $role) {
    $user = matrixUser($role);

    expect(api('GET', 'auth/me', token: bearer($user))->json('data.permissions'))->toBe(config("rbac.roles.{$role}"));
})->with(MATRIX_ROLES);

it('permissions de /auth/me do gestor com filial: as do perfil sem o manage dos catálogos globais (E.1)', function () {
    $permissions = api('GET', 'auth/me', token: bearer(matrixUser('manager_branch')))->json('data.permissions');

    expect($permissions)->toBe(array_values(array_diff(config('rbac.roles.manager'), MATRIX_G_BRANCH_DENIED)))
        ->not->toContain('equipment_families.manage', 'service_categories.manage')
        ->toContain('equipment_families.view', 'equipments.manage', 'cost_centers.manage', 'settings.manage');
});
