<?php

use App\Models\AuditLog;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function loginFrom(string $ip, string $email, string $password = 'SenhaErrada123'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password, 'device_name' => 'pest']);
}

it('6ª tentativa em 1 min com o mesmo e-mail+IP → 429 (envelope + Retry-After), sem audit do bloqueio', function () {
    User::factory()->create(['email' => 'alvo@example.com']);

    for ($i = 1; $i <= 5; $i++) {
        loginFrom('10.0.0.1', 'alvo@example.com')->assertStatus(422);
    }
    loginFrom('10.0.0.1', 'ALVO@example.com')
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', __('api.429'));

    // mesmo e-mail de outro IP e outro e-mail do mesmo IP seguem liberados
    loginFrom('10.0.0.2', 'alvo@example.com', UserFactory::PASSWORD)->assertOk();
    loginFrom('10.0.0.1', 'outro@example.com')->assertStatus(422);

    expect(AuditLog::query()->where('action', 'login_failed')->count())->toBe(6);
});

it('toda tentativa conta, inclusive as bem-sucedidas', function () {
    $user = User::factory()->create();

    for ($i = 1; $i <= 5; $i++) {
        loginFrom('10.0.0.3', $user->email, UserFactory::PASSWORD)->assertOk();
    }
    loginFrom('10.0.0.3', $user->email, UserFactory::PASSWORD)->assertStatus(429);
});

it('21ª tentativa/min do mesmo IP com e-mails distintos → 429; outro IP segue liberado', function () {
    for ($i = 1; $i <= 20; $i++) {
        loginFrom('10.0.0.9', "user{$i}@example.com")->assertStatus(422);
    }
    loginFrom('10.0.0.9', 'user21@example.com')->assertStatus(429);
    loginFrom('10.0.0.10', 'user21@example.com')->assertStatus(422);
});

it('o bloqueio expira após 1 minuto', function () {
    for ($i = 1; $i <= 5; $i++) {
        loginFrom('10.0.0.4', 'x@example.com');
    }
    loginFrom('10.0.0.4', 'x@example.com')->assertStatus(429);

    $this->travel(61)->seconds();
    loginFrom('10.0.0.4', 'x@example.com')->assertStatus(422);
});
