<?php

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->token = bearer(userWithRole(Role::Admin));
});

/** Mensagens de um 422 (message + errors), já planas. */
function messages422(string $method, string $uri, array $payload, string $token): array
{
    $res = api($method, $uri, $payload, $token)->assertStatus(422);

    return [$res->json('message'), ...Arr::flatten($res->json('errors'))];
}

/** Sem chave crua (validation.x / api.x) e sem nome técnico com sublinhado. */
function expectHumanMessages(array $messages): void
{
    foreach ($messages as $message) {
        expect($message)->not->toContain('validation.')->not->toContain('api.')
            ->and(preg_match('/[a-z]+_[a-z]+/i', $message))->toBe(0, "nome técnico em: {$message}");
    }
}

it('toda regra do Laravel tem tradução pt_BR (inclusive as variantes numeric/string/array/file)', function () {
    $en = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
    $ptBr = require lang_path('pt_BR/validation.php');

    $missing = array_values(array_diff(array_keys(Arr::dot(Arr::except($en, ['custom', 'attributes']))), array_keys(Arr::dot($ptBr))));
    expect($missing)->toBe([]);
});

it('regras numéricas: mensagem em pt_BR com o atributo traduzido', function (string $rule, mixed $value, string $expected) {
    $message = Validator::make(['preventive_lead_pct' => $value], ['preventive_lead_pct' => ['numeric', $rule]])->errors()->first('preventive_lead_pct');

    expect($message)->toBe($expected);
})->with([
    'gt' => ['gt:0', 0, 'O campo percentual de pré-alerta deve ser maior que 0.'],
    'gte' => ['gte:10', 5, 'O campo percentual de pré-alerta deve ser maior ou igual a 10.'],
    'lt' => ['lt:100', 100, 'O campo percentual de pré-alerta deve ser menor que 100.'],
    'lte' => ['lte:100', 101, 'O campo percentual de pré-alerta deve ser menor ou igual a 100.'],
    'between' => ['between:1,100', 0, 'O campo percentual de pré-alerta deve estar entre 1 e 100.'],
    'min' => ['min:1', 0, 'O campo percentual de pré-alerta deve ser pelo menos 1.'],
    'max' => ['max:100', 101, 'O campo percentual de pré-alerta não pode ser maior que 100.'],
    'decimal' => ['decimal:0,2', 1.234, 'O campo percentual de pré-alerta deve ter 0-2 casas decimais.'],
]);

it('um campo de cada cadastro: 422 com mensagem traduzida e atributo em pt_BR', function () {
    $branch = Branch::factory()->create();
    User::factory()->create(['email' => 'ana@example.com', 'branch_id' => $branch->id]);

    expect(api('POST', 'branches', ['name' => 'X', 'type' => 'filial'], $this->token)->assertStatus(422)->json('errors.code.0'))
        ->toBe('O campo código é obrigatório.');
    expect(api('POST', 'cost-centers', ['code' => 'CC', 'name' => 'X', 'branch_id' => 999999], $this->token)->assertStatus(422)->json('errors.branch_id.0'))
        ->toBe('O valor selecionado para filial é inválido.');
    expect(api('POST', 'equipment-families', ['code' => 'F', 'name' => 'X', 'category' => 'truck', 'preventive_lead_pct' => 101], $this->token)->assertStatus(422)->json('errors.preventive_lead_pct.0'))
        ->toBe('O campo percentual de pré-alerta deve ser menor ou igual a 100.');
    expect(api('POST', 'users', ['name' => 'X', 'username' => 'outra', 'email' => 'ANA@example.com', 'password' => 'SenhaForte2026', 'role' => 'admin'], $this->token)->assertStatus(422)->json('errors.email.0'))
        ->toBe('O valor informado para e-mail já está em uso.');
    expect(api('POST', 'employees', ['registration' => 'M', 'name' => 'X', 'job_type' => 'mechanic', 'branch_id' => $branch->id, 'cnh_number' => '1'], $this->token)->assertStatus(422)->json('errors.cnh_number.0'))
        ->toBe('O campo número da CNH não se aplica à função mecânico.');
});

it('nenhum 422 dos cadastros traz chave crua nem nome de campo com sublinhado', function () {
    $branch = Branch::factory()->create();
    $driver = Employee::factory()->driver()->create(['branch_id' => $branch->id]);
    $other = Branch::factory()->create();

    $cases = [
        ['POST', 'branches', ['code' => str_repeat('X', 40), 'type' => 'nave', 'state' => 'XX', 'is_active' => 'talvez']],
        ['POST', 'cost-centers', ['branch_id' => 'abc']],
        ['POST', 'equipment-families', ['preventive_lead_pct' => 0, 'tolerance_km' => -1, 'tolerance_hours' => 1.5, 'tolerance_days' => 'x', 'criticality' => 'x']],
        ['POST', 'equipment-families', ['code' => 'F', 'name' => 'X', 'category' => 'truck', 'preventive_lead_pct' => 50.123]],
        ['POST', 'users', ['email' => 'x', 'password' => 'curta', 'role' => 'root']],
        ['POST', 'users', ['name' => 'X', 'username' => 'xis', 'email' => 'x@example.com', 'password' => 'SenhaForte2026', 'role' => 'leader']],
        ['POST', 'employees', ['job_type' => 'mechanic', 'branch_id' => 999999, 'cost_center_id' => 'x', 'user_id' => 999999, 'hired_at' => '01/01/2024', 'cnh_category' => 'D', 'hourly_cost' => -1]],
        ['PATCH', "employees/{$driver->id}", ['job_type' => 'mechanic']],
        ['PATCH', "employees/{$driver->id}", ['user_id' => userWithRole(Role::Operator, $other)->id]],
        ['GET', 'users?per_page=500&sort=x&role=x', []],
        ['GET', 'audit-logs?from=2026-10-09&to=2026-10-01&auditable_type=x&request_id=1', []],
    ];

    foreach ($cases as [$method, $uri, $payload]) {
        expectHumanMessages(messages422($method, $uri, $payload, $this->token));
    }
});
