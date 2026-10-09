<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Concerns\BranchScoped;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const NEW_PASSWORD = 'OutraSenha2026';

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    $this->token = bearer($this->admin);
    $this->x = Branch::factory()->create(['code' => 'X', 'name' => 'Filial X']);
    $this->y = Branch::factory()->create(['code' => 'Y', 'name' => 'Filial Y']);
});

function userPayload(array $overrides = []): array
{
    return [
        'name' => 'Ana Souza',
        'username' => 'anasouza',
        'email' => 'ana@example.com',
        'password' => 'SenhaForte2026',
        'role' => 'operator',
        ...$overrides,
    ];
}

/** Logs de um usuário, do mais antigo ao mais novo. */
function userLogs(User $user): array
{
    return AuditLog::query()->where('auditable_type', $user->getMorphClass())->where('auditable_id', $user->id)->orderBy('id')->pluck('action')->all();
}

it('cria (201) com branch embutido, e-mail em minúsculas, sem password/remember_token e audit created sem senha', function () {
    $res = api('POST', 'users', userPayload(['email' => '  Ana@Example.COM ', 'branch_id' => $this->x->id]), $this->token)->assertCreated();

    expect($res->json('data'))->toMatchArray([
        'name' => 'Ana Souza', 'email' => 'ana@example.com', 'role' => 'operator', 'is_active' => true,
        'branch' => ['id' => $this->x->id, 'code' => 'X', 'name' => 'Filial X'],
        'last_login_at' => null, 'deleted_at' => null,
    ]);
    expect(array_keys($res->json('data')))->toBe(['id', 'name', 'username', 'email', 'role', 'branch', 'is_active', 'last_login_at', 'created_at', 'updated_at', 'deleted_at']);

    $log = AuditLog::query()->where('auditable_type', (new User)->getMorphClass())->where('auditable_id', $res->json('data.id'))->sole();
    expect($log->action)->toBe('created')->and($log->actor_id)->toBe($this->admin->id)
        ->and($log->new_values)->not->toHaveKeys(['password', 'remember_token']);
});

it('e-mail duplicado entre ativos → 422 (sem diferenciar caixa); de usuário excluído é permitido', function () {
    $other = User::factory()->create(['email' => 'ana@example.com', 'branch_id' => $this->x->id]);

    api('POST', 'users', userPayload(['email' => 'ANA@example.com', 'branch_id' => $this->x->id]), $this->token)
        ->assertStatus(422)->assertJsonValidationErrors(['email'], 'errors');
    api('PATCH', "users/{$other->id}", ['email' => 'ANA@EXAMPLE.COM'], $this->token)->assertOk(); // ignora o próprio

    $other->delete();
    api('POST', 'users', userPayload(['branch_id' => $this->x->id]), $this->token)->assertCreated();
});

it('política de senha da D.2 (mín. 10, maiúscula, minúscula e número); password obrigatório no create', function (string $password) {
    api('POST', 'users', userPayload(['password' => $password, 'branch_id' => $this->x->id]), $this->token)
        ->assertStatus(422)->assertJsonValidationErrors(['password'], 'errors');
})->with(['curta' => 'Abc12345', 'sem maiúscula' => 'senhafraca2026', 'sem minúscula' => 'SENHAFRACA2026', 'sem número' => 'SenhaSemNumero']);

it('campos obrigatórios no create e role fora do enum → 422', function () {
    api('POST', 'users', [], $this->token)->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'password', 'role'], 'errors');
    api('POST', 'users', userPayload(['role' => 'root', 'branch_id' => $this->x->id]), $this->token)->assertStatus(422)->assertJsonValidationErrors(['role'], 'errors');
});

it('branch_id obrigatório para role≠admin (create, admin→outro e branch null); admin sem filial é aceito', function () {
    api('POST', 'users', userPayload(), $this->token)->assertStatus(422)->assertJsonValidationErrors(['branch_id'], 'errors');
    api('POST', 'users', userPayload(['role' => 'admin']), $this->token)->assertCreated()->assertJsonPath('data.branch', null);

    $otherAdmin = userWithRole(Role::Admin);
    api('PATCH', "users/{$otherAdmin->id}", ['role' => 'leader'], $this->token)->assertStatus(422)->assertJsonValidationErrors(['branch_id'], 'errors');
    api('PATCH', "users/{$otherAdmin->id}", ['role' => 'leader', 'branch_id' => $this->y->id], $this->token)
        ->assertOk()->assertJsonPath('data.role', 'leader')->assertJsonPath('data.branch.id', $this->y->id);

    $operator = userWithRole(Role::Operator, $this->x);
    api('PUT', "users/{$operator->id}", ['branch_id' => null], $this->token)->assertStatus(422)->assertJsonValidationErrors(['branch_id'], 'errors');
    api('PUT', "users/{$operator->id}", ['role' => 'admin', 'branch_id' => null], $this->token)->assertOk()->assertJsonPath('data.branch', null);
});

it('branch_id inexistente ou de filial excluída → 422; filial inativa é aceita', function () {
    $deleted = Branch::factory()->create();
    $deleted->delete();
    $inactive = Branch::factory()->inactive()->create();

    api('POST', 'users', userPayload(['branch_id' => 999999]), $this->token)->assertStatus(422)->assertJsonValidationErrors(['branch_id'], 'errors');
    api('POST', 'users', userPayload(['branch_id' => $deleted->id]), $this->token)->assertStatus(422)->assertJsonValidationErrors(['branch_id'], 'errors');
    api('POST', 'users', userPayload(['branch_id' => $inactive->id]), $this->token)->assertCreated();
});

it('criar/alterar trava a filial (FOR SHARE) para não cruzar com a exclusão dela', function () {
    $lock = 'select "id" from "branches" where "branches"."id" = ? and "branches"."deleted_at" is null limit 1 for share';

    DB::enableQueryLog();
    $id = api('POST', 'users', userPayload(['branch_id' => $this->x->id]), $this->token)->assertCreated()->json('data.id');
    expect(collect(DB::getQueryLog())->pluck('query')->implode("\n"))->toContain($lock);

    DB::flushQueryLog();
    api('PATCH', "users/{$id}", ['branch_id' => $this->y->id], $this->token)->assertOk();
    expect(collect(DB::getQueryLog())->pluck('query')->implode("\n"))->toContain($lock);
});

it('show 200 sem segredos; excluído → 404; PUT e PATCH parciais', function () {
    $user = userWithRole(Role::Leader, $this->x);

    $data = api('GET', "users/{$user->id}", token: $this->token)->assertOk()->json('data');
    expect($data)->not->toHaveKeys(['password', 'remember_token'])->and($data['branch']['id'])->toBe($this->x->id);

    api('PUT', "users/{$user->id}", ['name' => 'Novo Nome'], $this->token)->assertOk()->assertJsonPath('data.name', 'Novo Nome')->assertJsonPath('data.role', 'leader');
    api('PATCH', "users/{$user->id}", ['is_active' => false], $this->token)->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.name', 'Novo Nome');

    $user->delete();
    api('GET', "users/{$user->id}", token: $this->token)->assertNotFound();
    api('PATCH', "users/{$user->id}", ['name' => 'X'], $this->token)->assertNotFound();
    api('DELETE', "users/{$user->id}", token: $this->token)->assertNotFound();
});

it('lista: filtros role/branch_id/is_active, q em name e email, sort por whitelist (padrão name) e with_trashed', function () {
    User::factory()->create(['name' => 'Carla', 'email' => 'carla@frota.test', 'role' => Role::Leader, 'branch_id' => $this->x->id]);
    User::factory()->create(['name' => 'Bruno', 'email' => 'bruno@frota.test', 'role' => Role::Mechanic, 'branch_id' => $this->y->id, 'is_active' => false]);
    $deleted = User::factory()->create(['name' => 'Davi', 'email' => 'davi@frota.test', 'branch_id' => $this->x->id]);
    $deleted->delete();
    $this->admin->update(['name' => 'Admin']);

    expect(api('GET', 'users', token: $this->token)->assertOk()->json('data.*.name'))->toBe(['Admin', 'Bruno', 'Carla']);
    expect(api('GET', 'users?role=leader', token: $this->token)->json('data.*.name'))->toBe(['Carla']);
    expect(api('GET', "users?branch_id={$this->y->id}", token: $this->token)->json('data.*.name'))->toBe(['Bruno']);
    expect(api('GET', 'users?is_active=0', token: $this->token)->json('data.*.name'))->toBe(['Bruno']);
    expect(api('GET', 'users?q=CARLA@', token: $this->token)->json('data.*.name'))->toBe(['Carla']);
    expect(api('GET', 'users?q=bruno@', token: $this->token)->json('data.*.name'))->toBe(['Bruno']);
    expect(api('GET', 'users?sort=-name', token: $this->token)->json('data.*.name'))->toBe(['Carla', 'Bruno', 'Admin']);
    expect(api('GET', 'users?sort=role,name', token: $this->token)->json('data.*.name'))->toBe(['Admin', 'Carla', 'Bruno']);
    expect(api('GET', 'users?with_trashed=1', token: $this->token)->json('data.*.name'))->toBe(['Admin', 'Bruno', 'Carla', 'Davi']);

    foreach (['users?sort=password', 'users?sort=branch_id', 'users?role=root', 'users?per_page=101'] as $uri) {
        api('GET', $uri, token: $this->token)->assertStatus(422);
    }
    api('GET', 'users?sort=email', token: $this->token)->assertOk();
    api('GET', 'users?sort=-created_at', token: $this->token)->assertOk();
    api('GET', 'users?sort=-last_login_at', token: $this->token)->assertOk();
});

it('trocar a senha de outro usuário revoga TODOS os tokens dele e audita password_changed sem a senha', function () {
    $target = userWithRole(Role::Operator, $this->x);
    $t1 = bearer($target);
    bearer($target);

    api('PATCH', "users/{$target->id}", ['password' => NEW_PASSWORD], $this->token)->assertOk();

    expect($target->tokens()->count())->toBe(0);
    api('GET', 'auth/me', token: $t1)->assertUnauthorized();
    api('POST', 'auth/login', ['login' => $target->email, 'password' => NEW_PASSWORD, 'device_name' => 'pest'])->assertOk();

    $log = AuditLog::query()->where('action', 'password_changed')->sole();
    expect($log->actor_id)->toBe($this->admin->id)->and($log->auditable_id)->toBe($target->id)
        ->and($log->metadata)->toBe(['revoked_tokens' => 2])
        ->and($log->old_values)->toBeNull()->and($log->new_values)->toBeNull();
});

it('desativar revoga todos os tokens do usuário-alvo', function () {
    $target = userWithRole(Role::Leader, $this->x);
    $token = bearer($target);

    api('PATCH', "users/{$target->id}", ['is_active' => false], $this->token)->assertOk();

    expect($target->tokens()->count())->toBe(0);
    api('GET', 'auth/me', token: $token)->assertUnauthorized();
});

it('excluir revoga todos os tokens, audita deleted e o token antigo deixa de valer', function () {
    $target = userWithRole(Role::Mechanic, $this->x);
    $token = bearer($target);

    api('DELETE', "users/{$target->id}", token: $this->token)->assertOk()->assertJsonPath('data', null);

    expect($target->tokens()->count())->toBe(0)->and(User::withTrashed()->find($target->id)->trashed())->toBeTrue();
    expect(userLogs($target))->toBe(['created', 'deleted']);
    api('GET', 'auth/me', token: $token)->assertUnauthorized();
});

it('o próprio admin trocando a senha por /users mantém o token atual e revoga os demais', function () {
    $other = bearer($this->admin);

    api('PATCH', "users/{$this->admin->id}", ['password' => NEW_PASSWORD], $this->token)->assertOk();

    expect($this->admin->tokens()->count())->toBe(1);
    api('GET', 'auth/me', token: $this->token)->assertOk();
    api('GET', 'auth/me', token: $other)->assertUnauthorized();
});

it('auto-exclusão (409): excluir, desativar ou mudar o próprio role; mesmo role e is_active=true passam', function () {
    $me = $this->admin->id;

    api('DELETE', "users/{$me}", token: $this->token)->assertStatus(409)->assertJsonPath('status', 'error')->assertJsonPath('message', __('api.user_self_delete'));
    api('PATCH', "users/{$me}", ['is_active' => false], $this->token)->assertStatus(409)->assertJsonPath('errors.is_active.0', __('api.user_self_deactivate'));
    api('PUT', "users/{$me}", ['role' => 'leader', 'branch_id' => $this->x->id], $this->token)->assertStatus(409)->assertJsonPath('errors.role.0', __('api.user_self_role'));

    api('PATCH', "users/{$me}", ['role' => 'admin', 'is_active' => true, 'name' => 'Eu'], $this->token)->assertOk()->assertJsonPath('data.name', 'Eu');
    expect(User::query()->find($me))->role->toBe(Role::Admin)->is_active->toBeTrue()->deleted_at->toBeNull();
});

it('último admin ativo (409): excluir, desativar ou rebaixar quando o outro admin já saiu (corrida)', function (string $method, array $payload) {
    $target = userWithRole(Role::Admin);
    // Simula a transação concorrente que já desativou o admin autenticado: só $target segue admin ativo.
    $this->admin->forceFill(['is_active' => false])->saveQuietly();
    Sanctum::actingAs($this->admin->refresh()->forceFill(['is_active' => true]));

    $payload += isset($payload['role']) ? ['branch_id' => $this->x->id] : [];
    $this->json($method, "/api/v1/users/{$target->id}", $payload)
        ->assertStatus(409)->assertJsonPath('message', __('api.user_last_admin'));

    expect($target->refresh())->role->toBe(Role::Admin)->is_active->toBeTrue()->deleted_at->toBeNull();
})->with([
    'excluir' => ['DELETE', []],
    'desativar' => ['PATCH', ['is_active' => false]],
    'rebaixar' => ['PUT', ['role' => 'leader']],
]);

it('último admin: a contagem trava os admins ativos (FOR UPDATE) na transação; com 2 admins ativos a operação passa', function () {
    $other = userWithRole(Role::Admin);
    $lock = 'select "id" from "users" where "role" = ? and "is_active" = ? and "users"."deleted_at" is null order by "id" asc for update';

    DB::enableQueryLog();
    api('PATCH', "users/{$other->id}", ['is_active' => false], $this->token)->assertOk();
    expect(collect(DB::getQueryLog())->pluck('query')->implode("\n"))->toContain($lock);

    api('PATCH', "users/{$other->id}", ['is_active' => true], $this->token)->assertOk();
    api('PUT', "users/{$other->id}", ['role' => 'leader', 'branch_id' => $this->x->id], $this->token)->assertOk();
    api('DELETE', "users/{$other->id}", token: $this->token)->assertOk();
});

it('restore: 200 + audit restored; 409 se não excluído, e-mail reutilizado ou filial excluída', function () {
    $user = User::factory()->create(['email' => 'eva@example.com', 'branch_id' => $this->x->id]);
    api('POST', "users/{$user->id}/restore", token: $this->token)->assertStatus(409);

    $user->delete();
    api('POST', "users/{$user->id}/restore", token: $this->token)->assertOk()->assertJsonPath('data.deleted_at', null);
    expect(userLogs($user))->toBe(['created', 'deleted', 'restored']);

    $user->delete();
    $reuse = User::factory()->create(['email' => 'eva@example.com', 'branch_id' => $this->y->id]);
    api('POST', "users/{$user->id}/restore", token: $this->token)->assertStatus(409)->assertJsonPath('errors.email.0', __('api.restore_email_taken'));

    $reuse->delete();
    $this->x->delete(); // sem usuários ativos na filial: a exclusão passa
    api('POST', "users/{$user->id}/restore", token: $this->token)->assertStatus(409)->assertJsonPath('message', __('api.restore_branch_deleted'));
});

it('filial com usuário ativo não pode ser excluída (409 com dependents users)', function () {
    userWithRole(Role::Operator, $this->x);

    api('DELETE', "branches/{$this->x->id}", token: $this->token)->assertStatus(409)->assertJsonPath('errors.dependents', ['users']);
});

it('auditoria sem senha nem token: nenhum log guarda password, remember_token, hash ou a senha em texto', function () {
    $id = api('POST', 'users', userPayload(['branch_id' => $this->x->id]), $this->token)->json('data.id');
    api('PATCH', "users/{$id}", ['password' => NEW_PASSWORD, 'name' => 'Ana S.'], $this->token)->assertOk();
    api('DELETE', "users/{$id}", token: $this->token)->assertOk();
    api('POST', "users/{$id}/restore", token: $this->token)->assertOk();

    $hash = User::query()->findOrFail($id)->getAuthPassword();
    $logs = AuditLog::query()->where('auditable_id', $id)->where('auditable_type', (new User)->getMorphClass())->orderBy('id')->get();
    expect($logs->pluck('action')->all())->toBe(['created', 'updated', 'password_changed', 'deleted', 'restored']);
    foreach ($logs as $log) {
        $json = json_encode([$log->old_values, $log->new_values, $log->metadata]);
        expect($json)->not->toContain('password')->not->toContain('remember_token')
            ->not->toContain(NEW_PASSWORD)->not->toContain('SenhaForte2026')->not->toContain($hash);
    }
});

it('User NUNCA usa BranchScoped nem o BranchScope (alerta da F1-10)', function () {
    expect(class_uses_recursive(User::class))->not->toContain(BranchScoped::class);
    expect((new User)->getGlobalScopes())->not->toHaveKey(BranchScope::class);

    // Líder autenticado: a consulta de usuários não é filtrada pela filial dele.
    $leader = userWithRole(Role::Leader, $this->x);
    User::factory()->create(['branch_id' => $this->y->id]);
    $this->actingAs($leader);
    expect(User::query()->where('branch_id', $this->y->id)->count())->toBe(1);
});

it('/meta/enums devolve roles (valores do enum Role)', function () {
    api('GET', 'meta/enums', token: $this->token)->assertOk()->assertJsonPath('data.enums.roles', ['operator', 'mechanic', 'leader', 'admin']);
});
