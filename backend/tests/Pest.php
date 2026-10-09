<?php

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// A guarda do banco de testes está em Tests\TestCase::createApplication (roda após o PHPUnit aplicar o
// phpunit.xml e antes de qualquer trait de banco).
pest()->extend(TestCase::class)->in('Feature');

/** Únicas rotas de api/v1 sem autenticação/permissão (allowlist da spec E). */
const RBAC_PUBLIC_ROUTES = ['api/v1/health', 'api/v1/auth/login', 'api/v1/auth/forgot-password', 'api/v1/auth/reset-password'];

/** Cada chamada simula uma requisição nova (os guards guardam o usuário entre requisições do mesmo teste). */
function api(string $method, string $uri, array $data = [], ?string $token = null): TestResponse
{
    app('auth')->forgetGuards();
    $headers = $token === null ? [] : ['Authorization' => "Bearer {$token}"];

    return test()->json($method, '/api/v1/'.$uri, $data, $headers);
}

/** Usuário do perfil (admin sem filial, salvo se $branch for informada). */
function userWithRole(Role $role, ?Branch $branch = null): User
{
    $factory = $role === Role::Admin ? User::factory()->admin() : User::factory()->role($role);

    return $factory->create($branch === null ? [] : ['branch_id' => $branch->id]);
}

/** Token Bearer emitido direto (sem passar pelo login nem pelo throttle). */
function bearer(User $user): string
{
    return $user->createToken('pest', ['*'], now()->addHour())->plainTextToken;
}
