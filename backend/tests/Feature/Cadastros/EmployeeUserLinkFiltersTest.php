<?php

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Ajustes de contrato pedidos para a F1-31 (UI Colaboradores e Usuários), aprovados na D.2:
 * job_type em lista em /employees; employee e has_employee em /users.
 */
beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    $this->token = bearer($this->admin);
    $this->branch = Branch::factory()->create();
});

it('/employees: job_type aceita lista separada por vírgula (aba Equipe Adm) e o valor único continua valendo', function () {
    foreach (['driver' => 'Ana', 'mechanic' => 'Bia', 'leader' => 'Caio', 'admin_staff' => 'Davi'] as $type => $name) {
        Employee::factory()->create(['job_type' => $type, 'name' => $name, 'branch_id' => $this->branch->id]);
    }
    $names = fn (string $query) => api('GET', "employees?{$query}", token: $this->token)->assertOk()->json('data.*.name');

    expect($names('job_type=leader,admin_staff'))->toBe(['Caio', 'Davi']);
    expect($names('job_type=driver'))->toBe(['Ana']);
    expect($names('job_type=mechanic,driver,mechanic'))->toBe(['Ana', 'Bia']);
});

it('/employees: valor inválido em qualquer posição da lista → 422 em job_type', function (string $value) {
    api('GET', 'employees?job_type='.urlencode($value), token: $this->token)->assertStatus(422)->assertJsonValidationErrors(['job_type'], 'errors');
})->with(['pilot', 'leader,pilot', 'pilot,leader', 'leader,,admin_staff', 'leader,', ',leader', 'leader, admin_staff']);

it('/users: item traz employee {id, registration, name} só do colaborador não excluído; null sem vínculo', function () {
    $linked = userWithRole(Role::Operator, $this->branch);
    $employee = Employee::factory()->create(['registration' => 'MAT-1', 'name' => 'Ana Colab', 'branch_id' => $this->branch->id, 'user_id' => $linked->id]);
    $free = userWithRole(Role::Operator, $this->branch);

    api('GET', "users/{$linked->id}", token: $this->token)->assertOk()
        ->assertJsonPath('data.employee', ['id' => $employee->id, 'registration' => 'MAT-1', 'name' => 'Ana Colab']);
    api('GET', "users/{$free->id}", token: $this->token)->assertOk()->assertJsonPath('data.employee', null);

    $employee->delete();
    api('GET', "users/{$linked->id}", token: $this->token)->assertOk()->assertJsonPath('data.employee', null);
    api('PATCH', "users/{$free->id}", ['name' => 'Livre'], $this->token)->assertOk()->assertJsonPath('data.employee', null);
});

it('/users: has_employee=1|0 filtra pelo vínculo não excluído; outro valor → 422', function () {
    $linked = userWithRole(Role::Operator, $this->branch);
    Employee::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $linked->id]);
    $unlinked = userWithRole(Role::Operator, $this->branch);
    $exLinked = userWithRole(Role::Operator, $this->branch);
    Employee::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $exLinked->id])->delete();

    $ids = fn (string $query) => collect(api('GET', "users?{$query}", token: $this->token)->assertOk()->json('data.*.id'))->sort()->values()->all();

    expect($ids('has_employee=1'))->toBe([$linked->id]);
    expect($ids('has_employee=0'))->toBe(collect([$this->admin->id, $unlinked->id, $exLinked->id])->sort()->values()->all());
    foreach (['sim', '2', 'x'] as $value) {
        api('GET', "users?has_employee={$value}", token: $this->token)->assertStatus(422)->assertJsonValidationErrors(['has_employee'], 'errors');
    }
});

it('/users: sem N+1 — a contagem de queries é a mesma com 1 e com N usuários vinculados', function () {
    $queries = function (): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        api('GET', 'users?per_page=100', token: $this->token)->assertOk();

        return collect(DB::getQueryLog())->pluck('query')->all();
    };
    $link = function (int $n): void {
        for ($i = 0; $i < $n; $i++) {
            $user = User::factory()->create(['branch_id' => $this->branch->id]);
            Employee::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $user->id]);
        }
    };

    // Relógio parado: o Sanctum só regrava last_used_at do token quando o segundo muda, e isso somaria uma
    // query aleatória à contagem.
    $this->freezeSecond();
    $link(1);
    $queries(); // aquecimento
    $one = $queries();
    $link(20);
    $many = $queries();

    expect(count($many))->toBe(count($one), "queries a mais:\n".implode("\n", array_diff($many, $one)));
});
