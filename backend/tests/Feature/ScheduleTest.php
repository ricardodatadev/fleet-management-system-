<?php

use App\Console\Commands\SchedulerHeartbeat;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

// RefreshDatabase no arquivo: em um teste só (->uses) ele não vale no Pest, e o prune gravaria no banco.
uses(RefreshDatabase::class);

/** Evento agendado cujo comando contém $needle. */
function scheduledEvent(string $needle): ?Event
{
    return collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains((string) $e->command, $needle));
}

it('sanctum:prune-expired está agendado diariamente, sem sobreposição', function () {
    $event = scheduledEvent('sanctum:prune-expired');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->command)->toContain('--hours=24');
});

it('o batimento do scheduler está agendado a cada minuto, sem sobreposição', function () {
    $event = scheduledEvent('scheduler:heartbeat');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('scheduler:heartbeat grava o arquivo que o healthcheck lê', function () {
    // storage temporário: no dev o storage/ é o mesmo do container scheduler, e um arquivo gravado pelo
    // teste faria o healthcheck passar sem o agendador estar rodando
    $storage = sys_get_temp_dir().'/heartbeat-'.bin2hex(random_bytes(4));
    mkdir($storage.'/framework', 0777, true);
    app()->useStoragePath($storage);

    $this->artisan('scheduler:heartbeat')->assertSuccessful();

    expect(file_exists(SchedulerHeartbeat::path()))->toBeTrue()
        ->and(SchedulerHeartbeat::path())->toStartWith($storage)
        ->and(time() - filemtime(SchedulerHeartbeat::path()))->toBeLessThan(5);

    unlink(SchedulerHeartbeat::path());
    rmdir($storage.'/framework');
    rmdir($storage);
});

it('o prune remove tokens expirados há mais de 24 h e mantém os demais', function () {
    $user = User::factory()->create();
    $user->createToken('velho', ['*'], now()->subDays(2));
    $user->createToken('recente', ['*'], now()->subHours(2));
    $user->createToken('valido', ['*'], now()->addHour());

    $this->artisan('sanctum:prune-expired --hours=24')->assertSuccessful();

    expect($user->tokens()->pluck('name')->sort()->values()->all())->toBe(['recente', 'valido']);
});
