<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

const RESET_PASSWORD = 'NovaSenha2026';

beforeEach(fn () => Notification::fake());

function forgot(string $email, string $ip = '10.1.1.1'): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/forgot-password', ['email' => $email]);
}

function resetWith(string $email, string $token, string $password = RESET_PASSWORD, ?string $confirmation = null, string $ip = '10.2.2.2'): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/reset-password', [
        'email' => $email, 'token' => $token, 'password' => $password, 'password_confirmation' => $confirmation ?? $password,
    ]);
}

/** Token do último e-mail de redefinição enviado ao usuário (Notification::fake). */
function sentToken(User $user): string
{
    $token = null;
    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    return $token;
}

it('forgot: sempre 200 com a mesma mensagem (existente, inexistente, inativo, excluído); só o ativo recebe e é auditado', function () {
    $active = User::factory()->create(['email' => 'ativo@example.com']);
    $inactive = User::factory()->inactive()->create(['email' => 'inativo@example.com']);
    $deleted = User::factory()->create(['email' => 'excluido@example.com']);
    $deleted->delete();

    $bodies = collect(['ATIVO@example.com ', 'ninguem@example.com', 'inativo@example.com', 'excluido@example.com'])
        ->map(fn ($email, $i) => forgot($email, "10.1.1.{$i}")->assertOk()->json());
    expect($bodies->unique(fn ($b) => json_encode($b)))->toHaveCount(1);
    expect($bodies->first())->toMatchArray(['status' => 'success', 'message' => __('auth.reset_link_sent'), 'data' => null]);

    Notification::assertSentTo($active, ResetPasswordNotification::class);
    Notification::assertNotSentTo([$inactive, $deleted], ResetPasswordNotification::class);
    Notification::assertCount(1);

    $log = AuditLog::query()->where('action', 'password_reset_requested')->sole();
    expect($log->actor_id)->toBeNull()->and($log->auditable_id)->toBe($active->id)->and($log->metadata)->toBeNull();
});

it('forgot: e-mail vazio ou malformado → 422 em errors.email', function () {
    forgot('')->assertStatus(422)->assertJsonPath('errors.email.0', 'O campo e-mail é obrigatório.');
    forgot('nao-eh-email')->assertStatus(422)->assertJsonPath('errors.email.0', 'O campo e-mail deve ser um e-mail válido.');
});

it('o e-mail vai pela fila, depois do commit, em pt_BR, com o nome de config(app.name) e o link em APP_FRONTEND_URL', function () {
    config(['app.frontend_url' => 'https://frota.exemplo.test', 'app.name' => 'Marca X']);
    $user = User::factory()->create(['email' => 'ana@example.com']);

    forgot('ana@example.com')->assertOk();
    $token = sentToken($user);

    $notification = new ResetPasswordNotification($token);
    expect($notification)->toBeInstanceOf(ShouldQueue::class)->and($notification->afterCommit)->toBeTrue();
    expect($notification->url($user))->toBe('https://frota.exemplo.test/redefinir-senha?'.http_build_query(['token' => $token, 'email' => 'ana@example.com']));

    $mail = $notification->toMail($user);
    expect($mail->subject)->toBe('Redefinição de senha — Marca X')
        ->and($mail->actionText)->toBe('Redefinir senha')
        ->and($mail->actionUrl)->toStartWith('https://frota.exemplo.test/redefinir-senha?token=')
        ->and(implode(' ', $mail->introLines))->toContain('Marca X');
    $html = (string) $mail->render();
    expect($html)->toContain('Marca X')->toContain('Redefinir senha')->not->toContain('Hello!')->not->toContain('Regards');
});

it('forgot enfileira a notificação (SendQueuedNotifications) em vez de enviar na requisição', function () {
    Notification::swap(new ChannelManager(app())); // canal real (sem o fake do beforeEach), fila falsa
    Queue::fake();
    $user = User::factory()->create();

    forgot($user->email)->assertOk();

    Queue::assertPushed(SendQueuedNotifications::class, fn ($job) => $job->notification instanceof ResetPasswordNotification);
});

it('forgot: throttle 3/min por e-mail + IP e 10/min por IP (429 com Retry-After)', function () {
    for ($i = 1; $i <= 3; $i++) {
        forgot('alvo@example.com', '10.3.3.3')->assertOk();
    }
    forgot('ALVO@example.com', '10.3.3.3')->assertStatus(429)->assertHeader('Retry-After');
    forgot('alvo@example.com', '10.3.3.4')->assertOk(); // outro IP

    for ($i = 1; $i <= 10; $i++) {
        forgot("u{$i}@example.com", '10.4.4.4')->assertOk();
    }
    forgot('u11@example.com', '10.4.4.4')->assertStatus(429);
});

it('reset: token válido redefine, revoga TODOS os tokens, audita e não faz login automático', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    bearer($user);
    bearer($user);
    forgot('ana@example.com')->assertOk();

    resetWith('ana@example.com', sentToken($user))
        ->assertOk()->assertJsonPath('message', __('auth.password_reset'))->assertJsonPath('data', null);

    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->count())->toBe(0);
    api('POST', 'auth/login', ['login' => 'ana@example.com', 'password' => RESET_PASSWORD, 'device_name' => 'pest'])->assertOk();
    api('POST', 'auth/login', ['login' => 'ana@example.com', 'password' => UserFactory::PASSWORD, 'device_name' => 'pest'])->assertStatus(422);

    $log = AuditLog::query()->where('action', 'password_reset')->sole();
    expect($log->actor_id)->toBe($user->id)->and($log->auditable_id)->toBe($user->id)->and($log->metadata)->toBe(['revoked_tokens' => 2]);
});

it('reset: token reutilizado, expirado, de outro e-mail, anterior a um pedido novo ou de usuário desativado → 422 genérico', function () {
    $invalid = fn (TestResponse $r) => $r->assertStatus(422)->assertJsonPath('errors', ['token' => [__('auth.reset_invalid')]]);
    $ana = User::factory()->create(['email' => 'ana@example.com']);
    $bia = User::factory()->create(['email' => 'bia@example.com']);

    // reutilizado
    forgot('ana@example.com')->assertOk();
    $token = sentToken($ana);
    resetWith('ana@example.com', $token)->assertOk();
    $invalid(resetWith('ana@example.com', $token, 'OutraSenha2026'));

    // de outro e-mail
    forgot('bia@example.com', '10.1.1.2')->assertOk();
    $invalid(resetWith('ana@example.com', sentToken($bia), 'OutraSenha2026'));

    // inválido
    $invalid(resetWith('bia@example.com', 'token-inventado'));

    // expirado (60 min)
    $this->travel(61)->minutes();
    $invalid(resetWith('bia@example.com', sentToken($bia), 'OutraSenha2026', ip: '10.2.2.3'));

    // um pedido novo invalida o anterior
    Notification::fake();
    forgot('bia@example.com', '10.1.1.3')->assertOk();
    $first = sentToken($bia);
    Notification::fake();
    forgot('bia@example.com', '10.1.1.3')->assertOk();
    $second = sentToken($bia);
    $invalid(resetWith('bia@example.com', $first, ip: '10.2.2.4'));

    // usuário desativado depois do pedido
    $bia->update(['is_active' => false]);
    $invalid(resetWith('bia@example.com', $second, ip: '10.2.2.4'));
    // e-mail inexistente
    $invalid(resetWith('ninguem@example.com', $second, ip: '10.2.2.4'));

    expect(AuditLog::query()->where('action', 'password_reset')->count())->toBe(1);
});

it('reset: política de senha e confirmação → 422 em errors.password (checadas antes do token)', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    forgot('ana@example.com')->assertOk();
    $token = sentToken($user);

    resetWith('ana@example.com', $token, 'fraca')->assertStatus(422)->assertJsonValidationErrors(['password'], 'errors')->assertJsonMissingValidationErrors(['token'], 'errors');
    resetWith('ana@example.com', $token, RESET_PASSWORD, 'Diferente2026')->assertStatus(422)
        ->assertJsonPath('errors.password.0', 'A confirmação do campo senha não confere.');
    $this->postJson('/api/v1/auth/reset-password', [])->assertStatus(422)->assertJsonValidationErrors(['email', 'token', 'password'], 'errors');

    resetWith('ana@example.com', $token)->assertOk(); // o token segue válido depois dos 422
});

it('reset: throttle 5/min por IP', function () {
    for ($i = 1; $i <= 5; $i++) {
        resetWith("x{$i}@example.com", 'token', ip: '10.5.5.5')->assertStatus(422);
    }
    resetWith('x6@example.com', 'token', ip: '10.5.5.5')->assertStatus(429)->assertHeader('Retry-After');
});

it('nenhum token de redefinição nem senha nos audit_logs', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    forgot('ana@example.com')->assertOk();
    $token = sentToken($user);
    resetWith('ana@example.com', $token)->assertOk();

    $all = DB::table('audit_logs')->get()->toJson();
    expect($all)->not->toContain($token)->not->toContain(RESET_PASSWORD)->not->toContain('$2y$')
        ->not->toContain(DB::table('users')->where('id', $user->id)->value('password'));
});
