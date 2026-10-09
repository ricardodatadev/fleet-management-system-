<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

function login(string $email, string $password = UserFactory::PASSWORD, string $device = 'pest'): TestResponse
{
    return api('POST', 'auth/login', ['email' => $email, 'password' => $password, 'device_name' => $device]);
}

function tokenFor(User $user, string $device = 'pest'): string
{
    return login($user->email, UserFactory::PASSWORD, $device)->assertOk()->json('data.token');
}

function authLogs(string $action): Collection
{
    return AuditLog::query()->where('action', $action)->orderBy('id')->get();
}

it('login ok devolve token Bearer, expires_at (SANCTUM_EXPIRATION) e o usuário; grava last_login_at', function () {
    $this->freezeSecond();
    $user = User::factory()->role(Role::Leader)->create(['email' => 'lider@example.com']);

    $res = login('lider@example.com')->assertOk()->assertJsonPath('status', 'success');

    expect($res->json('data.token_type'))->toBe('Bearer');
    expect($res->json('data.token'))->toBeString()->toContain('|');
    expect($res->json('data.expires_at'))->toBe(now()->addMinutes(720)->utc()->toJSON());
    expect($res->json('data.user'))->toBe([
        'id' => $user->id,
        'name' => $user->name,
        'email' => 'lider@example.com',
        'role' => 'leader',
        'branch' => ['id' => $user->branch->id, 'code' => $user->branch->code, 'name' => $user->branch->name],
    ]);
    expect($res->json('data.user'))->not->toHaveKey('password');

    $token = PersonalAccessToken::query()->sole();
    expect($token->name)->toBe('pest')->and($token->expires_at->equalTo(now()->addMinutes(720)))->toBeTrue();
    expect($user->fresh()->last_login_at->equalTo(now()))->toBeTrue();
});

it('expiração do token segue SANCTUM_EXPIRATION (config sanctum.expiration)', function () {
    $this->freezeSecond();
    config(['sanctum.expiration' => 30]);
    $user = User::factory()->create();

    expect(login($user->email)->json('data.expires_at'))->toBe(now()->addMinutes(30)->utc()->toJSON());
});

it('e-mail é normalizado (maiúsculas/espaços) na gravação e no login', function () {
    $user = User::factory()->create(['email' => '  Fulano@Example.COM ']);
    expect($user->fresh()->email)->toBe('fulano@example.com');

    login('FULANO@example.com ')->assertOk();
});

it('senha errada → 422 genérico, idêntico ao de e-mail inexistente e ao de usuário excluído', function () {
    $user = User::factory()->create(['email' => 'existe@example.com']);
    $deleted = User::factory()->create(['email' => 'removido@example.com']);
    $deleted->delete();

    $wrong = login('existe@example.com', 'SenhaErrada123')->assertStatus(422);
    $missing = login('naoexiste@example.com', 'SenhaErrada123')->assertStatus(422);
    $trashed = login('removido@example.com')->assertStatus(422);

    $body = fn (TestResponse $r) => collect($r->json())->only(['status', 'message', 'errors', 'data'])->all();
    expect($body($wrong))->toBe($body($missing))->toBe($body($trashed));
    expect($wrong->json('errors'))->toBe(['email' => [__('auth.failed')]]);
    expect(PersonalAccessToken::count())->toBe(0);
});

it('payload inválido → 422 por campo', function () {
    api('POST', 'auth/login', ['email' => 'nao-eh-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password', 'device_name'], 'errors');
});

it('usuário inativo: 403 com senha correta, 422 genérico com senha errada', function () {
    User::factory()->inactive()->create(['email' => 'inativo@example.com']);

    login('inativo@example.com')->assertForbidden()->assertJsonPath('message', __('auth.inactive'));
    login('inativo@example.com', 'SenhaErrada123')->assertStatus(422);
    expect(PersonalAccessToken::count())->toBe(0);
});

it('audita login_succeeded com actor = auditable = usuário (sem updated por last_login_at)', function () {
    $user = User::factory()->role(Role::Mechanic)->create();
    $lastId = AuditLog::query()->max('id') ?? 0;

    login($user->email)->assertOk();

    $logs = AuditLog::query()->where('id', '>', $lastId)->orderBy('id')->get();
    expect($logs->pluck('action')->all())->toBe(['login_succeeded']);
    $log = $logs->sole();
    expect($log->actor_id)->toBe($user->id)->and($log->auditable_id)->toBe($user->id)
        ->and($log->auditable_type)->toBe($user->getMorphClass())
        ->and($log->actor_role)->toBe('mechanic')->and($log->actor_name)->toBe($user->name);
});

it('audita login_failed com metadata {email, reason}, ator e auditable nulos e sem senha', function () {
    User::factory()->create(['email' => 'a@example.com']);
    User::factory()->inactive()->create(['email' => 'b@example.com']);

    login('A@example.com', 'SenhaErrada999');
    login('ninguem@example.com', 'SenhaErrada999');
    login('b@example.com');

    $failed = authLogs('login_failed');
    expect($failed->map(fn ($l) => $l->metadata)->all())->toBe([
        ['email' => 'a@example.com', 'reason' => 'invalid_credentials'],
        ['email' => 'ninguem@example.com', 'reason' => 'invalid_credentials'],
        ['email' => 'b@example.com', 'reason' => 'inactive'],
    ]);
    foreach ($failed as $log) {
        expect($log->actor_id)->toBeNull()->and($log->auditable_type)->toBeNull()->and($log->auditable_id)->toBeNull();
    }

    $all = DB::table('audit_logs')->get()->toJson();
    expect($all)->not->toContain('SenhaErrada999')->not->toContain(UserFactory::PASSWORD)->not->toContain('$2y$');
});

it('token expirado → 401', function () {
    $token = tokenFor(User::factory()->create());
    api('GET', 'auth/me', token: $token)->assertOk();

    $this->travel(721)->minutes();
    api('GET', 'auth/me', token: $token)->assertUnauthorized()->assertJsonPath('status', 'error');
});

it('expires_at gravado no token é respeitado → 401', function () {
    $token = tokenFor(User::factory()->create());
    PersonalAccessToken::query()->update(['expires_at' => now()->subSecond()]);

    api('GET', 'auth/me', token: $token)->assertUnauthorized();
});

it('rotas autenticadas exigem token válido → 401', function (string $method, string $uri) {
    api($method, $uri)->assertUnauthorized()->assertJsonPath('message', __('api.401'));
    api($method, $uri, token: '999|token-invalido')->assertUnauthorized();
})->with([
    ['POST', 'auth/logout'],
    ['GET', 'auth/me'],
    ['PUT', 'auth/password'],
]);

it('logout revoga o token atual (e só ele) e audita', function () {
    $user = User::factory()->create();
    $a = tokenFor($user, 'a');
    $b = tokenFor($user, 'b');

    api('POST', 'auth/logout', token: $a)->assertOk()->assertJsonPath('data', null);

    api('GET', 'auth/me', token: $a)->assertUnauthorized();
    api('GET', 'auth/me', token: $b)->assertOk();
    expect(PersonalAccessToken::query()->pluck('name')->all())->toBe(['b']);

    $log = authLogs('logout')->sole();
    expect($log->actor_id)->toBe($user->id)->and($log->auditable_id)->toBe($user->id);
});

it('token de usuário inativado ou excluído (soft delete) deixa de autenticar → 401', function () {
    $inactive = User::factory()->create();
    $deleted = User::factory()->create();
    $t1 = tokenFor($inactive);
    $t2 = tokenFor($deleted);

    $inactive->update(['is_active' => false]);
    $deleted->delete();

    api('GET', 'auth/me', token: $t1)->assertUnauthorized();
    api('GET', 'auth/me', token: $t2)->assertUnauthorized();
});

it('me retorna user, employee null e as permissions do perfil (config/rbac.php)', function (Role $role) {
    $user = $role === Role::Admin ? User::factory()->admin()->create() : User::factory()->role($role)->create();

    $res = api('GET', 'auth/me', token: tokenFor($user))->assertOk();

    expect($res->json('data.user.id'))->toBe($user->id)->and($res->json('data.user.role'))->toBe($role->value);
    expect($res->json('data.user.branch'))->toBe($role === Role::Admin ? null : ['id' => $user->branch->id, 'code' => $user->branch->code, 'name' => $user->branch->name]);
    expect($res->json('data'))->toHaveKey('employee')->and($res->json('data.employee'))->toBeNull();
    expect($res->json('data.permissions'))->toBe(config("rbac.roles.{$role->value}"))->not->toBeEmpty();
})->with(Role::cases());

it('troca de senha: aplica a nova, mantém o token atual, revoga os demais e audita', function () {
    $user = User::factory()->create();
    $current = tokenFor($user, 'atual');
    $other = tokenFor($user, 'outro');

    api('PUT', 'auth/password', [
        'current_password' => UserFactory::PASSWORD,
        'password' => 'NovaSenha2026',
        'password_confirmation' => 'NovaSenha2026',
    ], $current)->assertOk()->assertJsonPath('data', null);

    api('GET', 'auth/me', token: $current)->assertOk();
    api('GET', 'auth/me', token: $other)->assertUnauthorized();

    login($user->email)->assertStatus(422);
    login($user->email, 'NovaSenha2026')->assertOk();

    $log = authLogs('password_changed')->sole();
    expect($log->actor_id)->toBe($user->id)->and($log->auditable_id)->toBe($user->id)
        ->and($log->metadata)->toBe(['revoked_tokens' => 1]);
    // a troca não gera "updated" (password/updated_at excluídos) e nada sensível vai para o log
    expect(authLogs('updated'))->toBeEmpty();
    expect(DB::table('audit_logs')->get()->toJson())->not->toContain('NovaSenha2026')->not->toContain('$2y$');
});

it('troca de senha valida a senha atual, a confirmação e a política de senha', function (array $payload, string $field) {
    $token = tokenFor(User::factory()->create());

    api('PUT', 'auth/password', $payload + [
        'current_password' => UserFactory::PASSWORD,
        'password_confirmation' => $payload['password'] ?? null,
    ], $token)->assertStatus(422)->assertJsonValidationErrors([$field], 'errors');

    expect(authLogs('password_changed'))->toBeEmpty();
})->with([
    'senha atual errada' => [['current_password' => 'Errada12345', 'password' => 'NovaSenha2026'], 'current_password'],
    'menos de 10' => [['password' => 'Curta12A'], 'password'],
    'sem maiúscula' => [['password' => 'semmaiuscula123'], 'password'],
    'sem minúscula' => [['password' => 'SEMMINUSCULA123'], 'password'],
    'sem número' => [['password' => 'SemNumeroAqui'], 'password'],
    'confirmação diferente' => [['password' => 'NovaSenha2026', 'password_confirmation' => 'Outra2026xx'], 'password'],
]);

it('a cadeia de auditoria continua íntegra após o fluxo de auth', function () {
    $user = User::factory()->create();
    login($user->email, 'Errada123456');
    $token = tokenFor($user);
    api('PUT', 'auth/password', ['current_password' => UserFactory::PASSWORD, 'password' => 'NovaSenha2026', 'password_confirmation' => 'NovaSenha2026'], $token)->assertOk();
    api('POST', 'auth/logout', token: $token)->assertOk();

    $this->artisan('audit:verify')->assertExitCode(0);
});
