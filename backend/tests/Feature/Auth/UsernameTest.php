<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Support\Users\UsernameGenerator;
use Database\Factories\UserFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const USERNAME_RULE = 'Use só letras minúsculas sem acento e números, de 3 a 30 caracteres.';

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    $this->token = bearer($this->admin);
    $this->branch = Branch::factory()->create();
});

/** Backfill de uma lista de e-mails, na ordem, como a migration faz. */
function backfill(array $emails): array
{
    $taken = [];

    return array_map(function (string $email) use (&$taken) {
        $username = UsernameGenerator::fromEmail($email, fn (string $c) => isset($taken[$c]));
        $taken[$username] = true;

        return $username;
    }, $emails);
}

it('backfill: acento, símbolos, e-mail curto, vazio, colisão com sufixo 2, 3… e corte em 30 contando o sufixo', function () {
    expect(backfill([
        'José.Conceição@empresa.com',   // acento + ponto
        'a.b-c_d+tag@x.com',            // símbolos
        'Al@x.com',                     // curto: completa com dígitos
        '++@x.com',                     // nada sobra
        'jose.conceicao@outra.com',     // colide com o 1º
        'JoseConceicao@terceira.com',   // colide de novo
    ]))->toBe(['joseconceicao', 'abcdtag', 'al0', '000', 'joseconceicao2', 'joseconceicao3']);

    $long = str_repeat('a', 40).'@x.com';
    expect(backfill([$long, $long]))->toBe([str_repeat('a', 30), str_repeat('a', 29).'2']);

    foreach (backfill(['Ünïcødé@x.com', 'ção@x.com', '1@x.com']) as $username) {
        expect($username)->toMatch(UsernameGenerator::PATTERN);
    }
});

it('CHECK do banco rejeita username fora de ^[a-z0-9]{3,30}$ no SQL', function (string $username) {
    $insert = fn () => DB::table('users')->insert([
        'name' => 'SQL', 'username' => $username, 'email' => 'sql@example.com', 'password' => 'x', 'role' => 'admin',
    ]);

    expect(fn () => DB::transaction($insert))->toThrow(QueryException::class);
})->with(['maiúscula' => 'Ana123', 'espaço' => 'ana souza', 'acento' => 'joão', 'ponto' => 'ana.s', 'sublinhado' => 'ana_s', 'curto' => 'ab', 'longo' => str_repeat('a', 31)]);

it('username obrigatório no create; maiúscula ou espaço nas pontas é aceito já normalizado', function () {
    $payload = ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'SenhaForte2026', 'role' => 'operator', 'branch_id' => $this->branch->id];

    api('POST', 'users', $payload, $this->token)->assertStatus(422)->assertJsonValidationErrors(['username'], 'errors');
    api('POST', 'users', [...$payload, 'username' => '  AnaSouza1 '], $this->token)->assertCreated()->assertJsonPath('data.username', 'anasouza1');
});

it('username com acento, espaço interno, ponto, _, -, @ ou tamanho fora de 3..30 → 422 com a mensagem, sem transliterar', function (string $username) {
    $user = User::factory()->create(['username' => 'original', 'branch_id' => $this->branch->id]);

    api('PATCH', "users/{$user->id}", ['username' => $username], $this->token)
        ->assertStatus(422)->assertJsonPath('errors.username.0', USERNAME_RULE);
    expect($user->refresh()->username)->toBe('original');
})->with(['acento' => 'joão', 'espaço interno' => 'ana souza', 'ponto' => 'ana.souza', 'sublinhado' => 'ana_souza', 'hífen' => 'ana-souza', 'arroba' => 'ana@souza', 'curto' => 'ab', 'longo' => str_repeat('a', 31)]);

it('username duplicado entre ativos → 422 (sem diferenciar caixa); de excluído é permitido; restore 409 se reutilizado', function () {
    $other = User::factory()->create(['username' => 'ana', 'branch_id' => $this->branch->id]);
    $user = User::factory()->create(['branch_id' => $this->branch->id]);

    api('PATCH', "users/{$user->id}", ['username' => 'ANA'], $this->token)->assertStatus(422)->assertJsonValidationErrors(['username'], 'errors');
    api('PATCH', "users/{$other->id}", ['username' => 'ana'], $this->token)->assertOk(); // ignora o próprio

    $other->delete();
    api('PATCH', "users/{$user->id}", ['username' => 'ana'], $this->token)->assertOk();
    api('POST', "users/{$other->id}/restore", token: $this->token)
        ->assertStatus(409)->assertJsonPath('errors.username.0', __('api.restore_username_taken'));
});

it('/users: q busca em username, sort por username; resposta, /auth/me e auditoria trazem username', function () {
    User::factory()->create(['name' => 'Zeca', 'username' => 'aaa', 'branch_id' => $this->branch->id]);
    User::factory()->create(['name' => 'Bia', 'username' => 'zzz', 'branch_id' => $this->branch->id]);
    $this->admin->update(['username' => 'mmm']);

    expect(api('GET', 'users?q=zz', token: $this->token)->json('data.*.name'))->toBe(['Bia']);
    expect(api('GET', 'users?sort=username', token: $this->token)->json('data.*.username'))->toBe(['aaa', 'mmm', 'zzz']);
    expect(api('GET', 'users?sort=-username', token: $this->token)->json('data.*.username'))->toBe(['zzz', 'mmm', 'aaa']);
    api('GET', 'auth/me', token: $this->token)->assertJsonPath('data.user.username', 'mmm');

    $id = api('POST', 'users', ['name' => 'Novo', 'username' => 'novo', 'email' => 'novo@example.com', 'password' => 'SenhaForte2026', 'role' => 'admin'], $this->token)->json('data.id');
    $log = AuditLog::query()->where('action', 'created')->where('auditable_type', (new User)->getMorphClass())->where('auditable_id', $id)->sole();
    expect($log->new_values['username'])->toBe('novo');
});

it('login por username com caixa mista e espaços nas pontas (v1.8: só username)', function () {
    $user = User::factory()->create(['username' => 'carlos', 'email' => 'carlos@example.com', 'branch_id' => $this->branch->id]);

    foreach (['CARLOS', ' carlos ', '  CaRlOs'] as $username) {
        api('POST', 'auth/login', ['username' => $username, 'password' => UserFactory::PASSWORD, 'device_name' => 'pest'])
            ->assertOk()->assertJsonPath('data.user.id', $user->id)->assertJsonPath('data.user.username', 'carlos');
    }
});

it('credencial errada → 422 genérico igual para senha errada, username inexistente e e-mail digitado (mesmo com a senha certa); inativo → 403 só com a senha certa', function () {
    User::factory()->create(['username' => 'carlos', 'email' => 'carlos@example.com', 'branch_id' => $this->branch->id]);
    User::factory()->inactive()->create(['username' => 'inativo', 'branch_id' => $this->branch->id]);
    $attempt = fn (string $username, string $password = 'SenhaErrada123') => api('POST', 'auth/login', ['username' => $username, 'password' => $password, 'device_name' => 'pest']);

    $bodies = collect([
        $attempt('carlos'),                                   // senha errada
        $attempt('ninguem'),                                  // inexistente
        $attempt('carlos@example.com', UserFactory::PASSWORD), // e-mail não entra no login
        $attempt('Fora Do Padrão!'),                          // sem validação de formato no login
    ])->map(fn ($r) => collect($r->assertStatus(422)->json())->only(['status', 'message', 'errors', 'data'])->all())
        ->unique(fn ($b) => json_encode($b));
    expect($bodies)->toHaveCount(1)->and($bodies->first()['errors'])->toBe(['username' => [__('auth.failed')]]);

    $attempt('inativo', UserFactory::PASSWORD)->assertForbidden()->assertJsonPath('message', __('auth.inactive'));
    $attempt('inativo')->assertStatus(422);

    $metadata = AuditLog::query()->where('action', 'login_failed')->orderBy('id')->pluck('metadata')->all();
    expect(array_column($metadata, 'username'))->toBe(['carlos', 'ninguem', 'carlos@example.com', 'fora do padrão!', 'inativo', 'inativo']);
});

it('throttle de login pela chave normalizada do username: caixa e espaços diferentes contam juntos', function () {
    User::factory()->create(['username' => 'alvo', 'branch_id' => $this->branch->id]);
    $from = fn (string $username, string $ip = '10.9.9.9') => $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/login', ['username' => $username, 'password' => 'SenhaErrada123', 'device_name' => 'pest']);

    foreach (['alvo', 'ALVO', ' Alvo ', 'aLvO', 'alvo '] as $username) {
        $from($username)->assertStatus(422);
    }
    $from('ALVO')->assertStatus(429)->assertHeader('Retry-After');
    $from('alvo', '10.9.9.8')->assertStatus(422); // outro IP segue liberado
});
