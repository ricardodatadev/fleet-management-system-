<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\EquipmentFamily;
use App\Models\User;
use App\Support\Rbac\Rbac;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
 * Matriz da seção E transcrita LITERALMENTE (fonte independente de config/rbac.php).
 * Colunas: O (operator), M (mechanic), L (leader), A (admin). 1 = ✔, 0 = ✖.
 * auth.*  → Gate implícito auth.session; *.restore/with_trashed → contidos em cada *.manage.
 */
const MATRIX_E = [
    'auth.*' => [1, 1, 1, 1],
    'branches.view' => [1, 1, 1, 1],
    'branches.manage' => [0, 0, 0, 1],
    'cost_centers.view' => [0, 0, 1, 1],
    'cost_centers.manage' => [0, 0, 0, 1],
    'equipment_families.view' => [0, 1, 1, 1],
    'equipment_families.manage' => [0, 0, 0, 1],
    'equipments.view' => [1, 1, 1, 1],
    'equipments.manage' => [0, 0, 0, 1],
    'employees.view' => [0, 0, 1, 1],
    'employees.manage' => [0, 0, 0, 1],
    'users.view / users.manage' => [0, 0, 0, 1],
    'settings.view' => [0, 0, 1, 1],
    'settings.manage' => [0, 0, 0, 1],
    'audit.view' => [0, 0, 0, 1],
    '*.restore, with_trashed' => [0, 0, 0, 1],
];

const MATRIX_ROLES = ['operator', 'mechanic', 'leader', 'admin'];

/** Permissões (Gates) que representam cada linha da matriz. */
function matrixRowAbilities(string $row): array
{
    return match ($row) {
        'auth.*' => [Rbac::SESSION],
        'users.view / users.manage' => ['users.view', 'users.manage'],
        '*.restore, with_trashed' => [...array_values(array_filter(array_keys(MATRIX_E), fn ($k) => preg_match('/^[a-z_]+\.manage$/', $k) === 1)), 'users.manage'],
        default => [$row],
    };
}

/** Dataset perfil × linha da matriz (16 linhas × 4 perfis = 64 casos). */
function matrixCases(): array
{
    $cases = [];
    foreach (MATRIX_E as $row => $flags) {
        foreach (MATRIX_ROLES as $i => $role) {
            $cases["{$role} · {$row}"] = [$role, $row, (bool) $flags[$i]];
        }
    }

    return $cases;
}

it('matriz E: cada perfil × cada linha concede/nega conforme a tabela', function (string $role, string $row, bool $expected) {
    $user = userWithRole(Role::from($role), $role === 'admin' ? null : Branch::factory()->create());

    foreach (matrixRowAbilities($row) as $ability) {
        expect(Gate::forUser($user)->allows($ability))->toBe($expected, "{$role} → {$ability}");
    }
})->with(matrixCases());

it('config/rbac.php é exatamente a matriz E (mesmas permissões, na ordem da tabela)', function () {
    foreach (MATRIX_ROLES as $i => $role) {
        $expected = [];
        foreach (MATRIX_E as $row => $flags) {
            if ($flags[$i] && ! in_array($row, ['auth.*', '*.restore, with_trashed'], true)) {
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
    $user = userWithRole(Role::from($role), $role === 'admin' ? null : Branch::factory()->create());
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
})->with(MATRIX_ROLES);

it('AuditLog: leitura só com audit.view e nenhuma escrita, nem para admin', function (string $role) {
    $user = userWithRole(Role::from($role), $role === 'admin' ? null : Branch::factory()->create());
    $log = AuditLog::query()->firstOrFail();
    $gate = Gate::forUser($user);

    expect($gate->allows('viewAny', AuditLog::class))->toBe($role === 'admin')
        ->and($gate->allows('view', $log))->toBe($role === 'admin')
        ->and($gate->allows('create', AuditLog::class))->toBeFalse()
        ->and($gate->allows('update', $log))->toBeFalse()
        ->and($gate->allows('delete', $log))->toBeFalse();
})->with(MATRIX_ROLES);

/*
 * Camada 2 — perfil × rota × HTTP esperado, para as rotas existentes.
 * Regra da H ("RBAC em cada cadastro"): cada F1-11..16 acrescenta aqui as linhas das suas rotas.
 * A URI é a da rota; os parâmetros {x} são trocados pelas fixtures de routeFixtures() (5º item: mapa
 * parâmetro → fixture, quando a rota precisa de outra, ex.: restore usa o registro excluído).
 */
function routeMatrix(): array
{
    $all = array_fill_keys(MATRIX_ROLES, 200);
    $adminOnly = fn (int $ok) => ['operator' => 403, 'mechanic' => 403, 'leader' => 403, 'admin' => $ok];
    $leaderAndAdmin = ['operator' => 403, 'mechanic' => 403, 'leader' => 200, 'admin' => 200];
    $mechanicLeaderAdmin = ['operator' => 403, 'mechanic' => 200, 'leader' => 200, 'admin' => 200];

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
        // F1-12 — centros de custo (cost_centers.view: L, A; manage: A). Fixture sem filial: visível ao L.
        ['GET', 'cost-centers', [], $leaderAndAdmin],
        ['POST', 'cost-centers', ['code' => 'CC-NOVO', 'name' => 'Novo'], $adminOnly(201)],
        ['GET', 'cost-centers/{cost_center}', [], $leaderAndAdmin],
        ['PUT', 'cost-centers/{cost_center}', ['name' => 'Renomeado'], $adminOnly(200)],
        ['PATCH', 'cost-centers/{cost_center}', ['name' => 'Renomeado'], $adminOnly(200)],
        ['DELETE', 'cost-centers/{cost_center}', [], $adminOnly(200)],
        ['POST', 'cost-centers/{cost_center}/restore', [], $adminOnly(200), ['cost_center' => 'trashed_cost_center']],
        // F1-13 — famílias de equipamento (equipment_families.view: M, L, A; manage: A)
        ['GET', 'equipment-families', [], $mechanicLeaderAdmin],
        ['POST', 'equipment-families', ['code' => 'FAM-NOVA', 'name' => 'Nova', 'category' => 'truck'], $adminOnly(201)],
        ['GET', 'equipment-families/{equipment_family}', [], $mechanicLeaderAdmin],
        ['PUT', 'equipment-families/{equipment_family}', ['name' => 'Renomeada'], $adminOnly(200)],
        ['PATCH', 'equipment-families/{equipment_family}', ['name' => 'Renomeada'], $adminOnly(200)],
        ['DELETE', 'equipment-families/{equipment_family}', [], $adminOnly(200)],
        ['POST', 'equipment-families/{equipment_family}/restore', [], $adminOnly(200), ['equipment_family' => 'trashed_equipment_family']],
        // F1-14 — colaboradores (employees.view: L, A; manage: A). Fixture na filial do usuário: visível ao L.
        ['GET', 'employees', [], $leaderAndAdmin],
        ['POST', 'employees', ['registration' => 'MAT-NOVA', 'name' => 'Novo', 'job_type' => 'leader', 'branch_id' => '{branch}'], $adminOnly(201)],
        ['GET', 'employees/{employee}', [], $leaderAndAdmin],
        ['PUT', 'employees/{employee}', ['name' => 'Renomeado'], $adminOnly(200)],
        ['PATCH', 'employees/{employee}', ['name' => 'Renomeado'], $adminOnly(200)],
        ['DELETE', 'employees/{employee}', [], $adminOnly(200)],
        ['POST', 'employees/{employee}/restore', [], $adminOnly(200), ['employee' => 'trashed_employee']],
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
    $trashedBranch = Branch::factory()->create();
    $trashedBranch->delete();
    $trashedCostCenter = CostCenter::factory()->global()->create();
    $trashedCostCenter->delete();
    $trashedFamily = EquipmentFamily::factory()->create();
    $trashedFamily->delete();
    $trashedUser = User::factory()->create();
    $trashedUser->delete();

    return [
        'branch' => Branch::factory()->create()->id, // sem dependentes: pode ser excluída
        'trashed_branch' => $trashedBranch->id,
        'cost_center' => CostCenter::factory()->global()->create()->id,
        'trashed_cost_center' => $trashedCostCenter->id,
        'equipment_family' => EquipmentFamily::factory()->create()->id,
        'trashed_equipment_family' => $trashedFamily->id,
        'user' => User::factory()->create()->id, // operador: pode ser excluído
        'trashed_user' => $trashedUser->id,
        'employee' => Employee::factory()->create(['branch_id' => $ownBranchId])->id,
        'trashed_employee' => $trashedEmployee->id,
        'audit_log' => AuditLog::query()->value('id'),
    ];
}

function routeMatrixCases(): array
{
    $cases = [];
    foreach (routeMatrix() as $row) {
        [$method, $uri, $payload, $expected] = $row;
        foreach ($expected as $role => $status) {
            $cases["{$role} {$method} /{$uri} → {$status}"] = [$role, $method, $uri, $payload, $status, $row[4] ?? []];
        }
    }

    return $cases;
}

it('rotas: perfil × rota × HTTP esperado', function (string $role, string $method, string $uri, array $payload, int $status, array $params) {
    $user = userWithRole(Role::from($role), $role === 'admin' ? null : Branch::factory()->create());
    $fixtures = routeFixtures($user);
    $uri = preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) $fixtures[$params[$m[1]] ?? $m[1]], $uri);
    // payload pode referenciar fixtures ('{branch}' → id)
    $payload = array_map(fn ($v) => is_string($v) && preg_match('/^\{(\w+)\}$/', $v, $m) ? $fixtures[$m[1]] : $v, $payload);

    api($method, $uri, $payload, bearer($user))->assertStatus($status);
})->with(routeMatrixCases());

it('o dataset por rota cobre todas as rotas protegidas de api/v1', function () {
    $covered = collect(routeMatrix())->map(fn ($r) => $r[0].' '.$r[1])->sort()->values()->all();
    $protected = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/') && ! in_array($r->uri(), RBAC_PUBLIC_ROUTES, true))
        ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS'], true))->map(fn ($m) => $m.' '.substr($r->uri(), 7)))
        ->sort()->values()->all();

    expect($covered)->toBe($protected);
});

it('permissions de /auth/me == config/rbac.php do perfil', function (string $role) {
    $user = userWithRole(Role::from($role), $role === 'admin' ? null : Branch::factory()->create());

    expect(api('GET', 'auth/me', token: bearer($user))->json('data.permissions'))->toBe(config("rbac.roles.{$role}"));
})->with(MATRIX_ROLES);
