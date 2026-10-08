<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Executa num savepoint: a violação esperada não aborta a transação do RefreshDatabase. */
function violates(Closure $fn): Closure
{
    return fn () => DB::transaction($fn);
}

it('branches: CHECK de type e código único só entre ativos (índice parcial)', function () {
    $branch = Branch::factory()->create(['code' => 'FIL-001']);

    expect(violates(fn () => Branch::factory()->create(['code' => 'FIL-001'])))->toThrow(QueryException::class);
    expect(violates(fn () => Branch::factory()->create(['type' => 'deposito'])))->toThrow(QueryException::class);

    $branch->delete();
    expect(Branch::factory()->create(['code' => 'FIL-001'])->exists)->toBeTrue();
});

it('users: role restrito pelo CHECK e filial obrigatória para não-admin', function () {
    expect(User::factory()->admin()->create()->branch_id)->toBeNull();
    expect(violates(fn () => User::factory()->create(['role' => Role::Leader, 'branch_id' => null])))->toThrow(QueryException::class);
    expect(violates(fn () => DB::table('users')->insert([
        'name' => 'X', 'email' => 'x@example.com', 'password' => 'x', 'role' => 'almoxarife', 'branch_id' => Branch::factory()->create()->id,
    ])))->toThrow(QueryException::class);
});

it('users: e-mail único só entre ativos (excluído libera o e-mail)', function () {
    $user = User::factory()->create(['email' => 'dup@example.com']);
    expect(violates(fn () => User::factory()->create(['email' => 'DUP@example.com'])))->toThrow(QueryException::class);

    $user->delete();
    expect(User::factory()->create(['email' => 'dup@example.com'])->exists)->toBeTrue();
});

it('users: FK de filial com ON DELETE RESTRICT', function () {
    $user = User::factory()->create();

    expect(violates(fn () => DB::table('branches')->where('id', $user->branch_id)->delete()))->toThrow(QueryException::class);
});

it('personal_access_tokens: expires_at é obrigatório', function () {
    $user = User::factory()->create();

    expect(violates(fn () => $user->createToken('sem-expiracao')))->toThrow(QueryException::class);
});

it('User é auditável (created/updated) sem password, remember_token nem last_login_at', function () {
    $user = User::factory()->create();
    $user->forceFill(['last_login_at' => now()])->save();
    $user->update(['name' => 'Novo Nome', 'password' => 'OutraSenha123']);

    $logs = AuditLog::query()->where('auditable_type', $user->getMorphClass())->orderBy('id')->get();
    expect($logs->pluck('action')->all())->toBe(['created', 'updated']);
    expect($logs[0]->new_values)->not->toHaveKeys(['password', 'remember_token']);
    expect($logs[1]->new_values)->toBe(['name' => 'Novo Nome']);
});

it('config/rbac.php: um perfil por valor do enum Role, listas explícitas sem curingas nem auth.*', function () {
    $roles = config('rbac.roles');
    expect(array_keys($roles))->toBe(Role::values());

    foreach ($roles as $permissions) {
        expect($permissions)->toBe(array_values(array_unique($permissions)));
        foreach ($permissions as $permission) {
            expect($permission)->toMatch('/^[a-z_]+\.(view|manage)$/')->not->toStartWith('auth.');
        }
    }
    expect($roles['operator'])->toBe(['branches.view', 'equipments.view']);
    expect($roles['admin'])->toContain('users.manage', 'audit.view', 'settings.manage');
});
