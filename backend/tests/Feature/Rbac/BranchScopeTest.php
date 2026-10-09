<?php

use App\Enums\Role;
use App\Models\Branch;
use App\Support\Api\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\Support\ScopeProbe;
use Tests\Support\ScopeProbePolicy;
use Tests\Support\ScopeProbeWithNull;

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::create('scope_probes', function ($table) {
        $table->id();
        $table->foreignId('branch_id')->nullable();
        $table->string('name');
        $table->timestamps();
    });
    Gate::policy(ScopeProbe::class, ScopeProbePolicy::class);

    // Rotas de teste no mesmo padrão dos cadastros: auth:sanctum + can:<permissão> + binding implícito.
    Route::prefix('api/v1/_t')->middleware(['api', 'auth:sanctum'])->group(function () {
        $list = fn (string $class) => fn () => ApiResponse::success($class::query()->orderBy('id')->pluck('name')->all());
        $show = fn (ScopeProbe $probe) => ApiResponse::success(['name' => $probe->name]);

        Route::get('equipments', $list(ScopeProbe::class))->middleware('can:equipments.view');
        Route::get('equipments/{probe}', $show)->middleware('can:equipments.view');
        Route::get('employees', $list(ScopeProbe::class))->middleware('can:employees.view');
        Route::get('employees/{probe}', $show)->middleware('can:employees.view');
        Route::get('cost-centers', $list(ScopeProbeWithNull::class))->middleware('can:cost_centers.view');
    });

    $this->x = Branch::factory()->create(['code' => 'X']);
    $this->y = Branch::factory()->create(['code' => 'Y']);
    $this->inX = ScopeProbe::query()->create(['branch_id' => $this->x->id, 'name' => 'x1']);
    $this->inY = ScopeProbe::query()->create(['branch_id' => $this->y->id, 'name' => 'y1']);
    $this->global = ScopeProbe::query()->create(['branch_id' => null, 'name' => 'sem-filial']);
});

it('L/M/O da filial X listam só a X e recebem 404 no registro da filial Y', function (Role $role) {
    $token = bearer(userWithRole($role, $this->x));

    api('GET', '_t/equipments', token: $token)->assertOk()->assertJsonPath('data', ['x1']);
    api('GET', "_t/equipments/{$this->inX->id}", token: $token)->assertOk()->assertJsonPath('data.name', 'x1');
    api('GET', "_t/equipments/{$this->inY->id}", token: $token)->assertNotFound()->assertJsonPath('status', 'error');
    api('GET', "_t/equipments/{$this->global->id}", token: $token)->assertNotFound();
})->with([Role::Operator, Role::Mechanic, Role::Leader]);

it('admin, com ou sem filial, vê tudo', function (bool $withBranch) {
    $token = bearer(userWithRole(Role::Admin, $withBranch ? $this->x : null));

    api('GET', '_t/equipments', token: $token)->assertOk()->assertJsonPath('data', ['x1', 'y1', 'sem-filial']);
    api('GET', "_t/equipments/{$this->inY->id}", token: $token)->assertOk();
})->with(['admin com filial' => true, 'admin sem filial' => false]);

it('403 tem precedência sobre o 404: sem a permissão, 403 até no id de outra filial', function () {
    $mechanic = bearer(userWithRole(Role::Mechanic, $this->x)); // sem employees.view
    api('GET', '_t/employees', token: $mechanic)->assertForbidden();
    api('GET', "_t/employees/{$this->inX->id}", token: $mechanic)->assertForbidden();
    api('GET', "_t/employees/{$this->inY->id}", token: $mechanic)->assertForbidden();
    api('GET', '_t/employees/999999', token: $mechanic)->assertForbidden();

    $leader = bearer(userWithRole(Role::Leader, $this->x)); // com employees.view
    api('GET', "_t/employees/{$this->inX->id}", token: $leader)->assertOk();
    api('GET', "_t/employees/{$this->inY->id}", token: $leader)->assertNotFound();

    api('GET', "_t/employees/{$this->inY->id}")->assertUnauthorized(); // 401 antes de tudo
});

it('branchScopeIncludesNull: L vê a própria filial + os sem filial, nunca a outra', function () {
    $token = bearer(userWithRole(Role::Leader, $this->x));

    api('GET', '_t/cost-centers', token: $token)->assertOk()->assertJsonPath('data', ['x1', 'sem-filial']);

    // fora de request autenticado (request sem token, guards novos): sem filtro
    app()->instance('request', Request::create('/'));
    Auth::forgetGuards();
    expect(ScopeProbeWithNull::query()->count())->toBe(3);
});

it('policy por instância aplica o mesmo escopo (view/update)', function () {
    $leader = userWithRole(Role::Leader, $this->x);
    $admin = userWithRole(Role::Admin, $this->y);

    expect(Gate::forUser($leader)->allows('view', $this->inX))->toBeTrue()
        ->and(Gate::forUser($leader)->allows('view', $this->inY))->toBeFalse()
        ->and(Gate::forUser($leader)->allows('view', $this->global))->toBeFalse()
        ->and(Gate::forUser($leader)->allows('view', (new ScopeProbeWithNull)->newFromBuilder($this->global->getAttributes())))->toBeTrue()
        ->and(Gate::forUser($leader)->allows('update', $this->inX))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view', $this->inX))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $this->inX))->toBeTrue();
});

it('sem usuário (console/fila) o escopo não filtra; validação exists nunca é filtrada', function () {
    Auth::forgetGuards();
    expect(ScopeProbe::query()->count())->toBe(3);

    Auth::setUser(userWithRole(Role::Operator, $this->x));
    expect(ScopeProbe::query()->pluck('name')->all())->toBe(['x1']);
    expect(Validator::make(['id' => $this->inY->id], ['id' => 'exists:scope_probes,id'])->passes())->toBeTrue();
    expect(ScopeProbe::query()->withoutGlobalScopes()->count())->toBe(3);
});

it('can: com parâmetro de rota é recusado (instância se autoriza no controller, após o binding)', function () {
    Route::prefix('api/v1/_t')->middleware(['api', 'auth:sanctum'])
        ->get('errado/{probe}', fn (ScopeProbe $probe) => 'x')->middleware('can:view,probe');

    api('GET', "_t/errado/{$this->inX->id}", token: bearer(userWithRole(Role::Admin)))->assertStatus(500);
});
