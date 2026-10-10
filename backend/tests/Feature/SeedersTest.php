<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\EquipmentFamily;
use App\Models\Scopes\BranchScope;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\SettingsService;
use App\Support\Users\UsernameGenerator;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\SeedInputException;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

const SEED_ADMIN_PASSWORD = 'AdminSeed2026';
const SEED_DEMO_PASSWORD = 'DemoSeed2026';

beforeEach(function () {
    config([
        'seed.admin' => ['username' => 'admin.seed', 'email' => 'admin.seed@example.com', 'password' => SEED_ADMIN_PASSWORD, 'name' => 'Admin Seed'],
        'seed.demo_password' => SEED_DEMO_PASSWORD,
    ]);
});

/** Contagem de cada tabela de negócio (para comparar 1º e 2º db:seed). */
function tableCounts(): array
{
    return collect(['branches', 'cost_centers', 'equipment_families', 'equipments', 'employees', 'users', 'settings', 'audit_logs'])
        ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
}

it('AdminSeeder: falha com mensagem clara se alguma variável faltar', function (string $missing, string $field) {
    config(["seed.admin.{$field}" => '']);

    expect(fn () => $this->seed(AdminSeeder::class))->toThrow(SeedInputException::class, $missing);
    expect(User::query()->count())->toBe(0);
})->with([
    ['SEED_ADMIN_USERNAME', 'username'],
    ['SEED_ADMIN_EMAIL', 'email'],
    ['SEED_ADMIN_PASSWORD', 'password'],
]);

it('AdminSeeder: username fora da regra v1.9 ou senha fora da política → mensagem clara, nada criado', function (string $field, string $value, string $message) {
    config(["seed.admin.{$field}" => $value]);

    expect(fn () => $this->seed(AdminSeeder::class))->toThrow(SeedInputException::class, $message);
    expect(User::query()->count())->toBe(0);
})->with([
    'username com _' => ['username', 'admin_seed', 'SEED_ADMIN_USERNAME'],
    'username com ponto no fim' => ['username', 'admin.', 'SEED_ADMIN_USERNAME'],
    'senha change-me' => ['password', 'change-me', 'senha'],
    'e-mail inválido' => ['email', 'nao-eh-email', 'e-mail'],
]);

it('AdminSeeder: cria o admin (username normalizado, sem filial) e é idempotente sem trocar a senha', function () {
    config(['seed.admin.username' => '  Admin.Seed ']);
    $this->seed(AdminSeeder::class);

    $admin = User::query()->where('username', 'admin.seed')->sole();
    expect($admin)->role->toBe(Role::Admin)->branch_id->toBeNull()->is_active->toBeTrue()
        ->and(Hash::check(SEED_ADMIN_PASSWORD, $admin->password))->toBeTrue();
    $hash = $admin->password;

    config(['seed.admin.password' => 'OutraSenha2026', 'seed.admin.email' => 'outro@example.com']);
    $this->seed(AdminSeeder::class);

    expect(User::query()->count())->toBe(1)
        ->and($admin->refresh()->password)->toBe($hash)
        ->and($admin->email)->toBe('admin.seed@example.com');
});

it('AdminSeeder: e-mail já usado por outro usuário → mensagem clara', function () {
    User::factory()->create(['email' => 'admin.seed@example.com']);

    expect(fn () => $this->seed(AdminSeeder::class))->toThrow(SeedInputException::class, 'SEED_ADMIN_EMAIL');
});

it('SettingsSeeder: grava os 4 globais com o default do registry; idempotente; não sobrescreve valor alterado pelo admin', function () {
    app(SettingsService::class)->put('warranty.alert_mode', 'global', null, 'hard_block'); // alterado pelo admin

    $this->seed(SettingsSeeder::class);
    $this->seed(SettingsSeeder::class);

    $globals = Setting::query()->where('scope_type', 'global')->get()->mapWithKeys(fn ($s) => [$s->key => $s->value])->all();
    expect($globals)->toEqual([
        'preventive.dispatch_mode' => 'suggestion',
        'workorder.block_close_without_labor' => false,
        'stock.allow_issue_with_fiscal_pending' => true,
        'warranty.alert_mode' => 'hard_block',
    ]);
    expect(Setting::query()->count())->toBe(4)
        ->and(AuditLog::query()->where('action', 'setting_changed')->count())->toBe(4); // 1 do admin + 3 criados
});

it('DemoSeeder: 2 filiais, 3 centros de custo, 5 famílias, ≥ 30 equipamentos, colaboradores e 1 usuário por perfil', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Branch::query()->where('code', 'like', 'DEMO-%')->count())->toBe(2)
        ->and(CostCenter::query()->withoutGlobalScope(BranchScope::class)->where('code', 'like', 'DEMO-%')->count())->toBe(3)
        ->and(EquipmentFamily::query()->where('code', 'like', 'DEMO-%')->count())->toBe(5)
        ->and(Equipment::query()->withoutGlobalScope(BranchScope::class)->where('code', 'like', 'DEMO-%')->count())->toBeGreaterThanOrEqual(30)
        ->and(Employee::query()->withoutGlobalScope(BranchScope::class)->where('registration', 'like', 'DEMO-%')->count())->toBeGreaterThanOrEqual(6);

    $users = User::query()->where('username', 'like', 'demo.%')->get()->keyBy('username');
    expect($users->keys()->sort()->values()->all())->toBe(['demo.admin', 'demo.lider', 'demo.mecanico', 'demo.operador'])
        ->and($users->map(fn ($u) => $u->role->value)->unique()->count())->toBe(4);
    foreach ($users as $username => $user) {
        expect($username)->toMatch(UsernameGenerator::PATTERN);
    }
});

it('DemoSeeder: dados coerentes com a consistência de filial', function () {
    $this->seed(DatabaseSeeder::class);

    foreach (Equipment::query()->withoutGlobalScope(BranchScope::class)->with(['costCenter', 'responsibleEmployee'])->get() as $eq) {
        expect($eq->costCenter->branch_id === null || $eq->costCenter->branch_id === $eq->branch_id)->toBeTrue("centro de custo de {$eq->code}");
        expect($eq->responsibleEmployee === null || $eq->responsibleEmployee->branch_id === $eq->branch_id)->toBeTrue("responsável de {$eq->code}");
    }
    foreach (Employee::query()->withoutGlobalScope(BranchScope::class)->whereNotNull('user_id')->with('user')->get() as $emp) {
        expect($emp->user->isAdmin() || $emp->user->branch_id === $emp->branch_id)->toBeTrue("vínculo de {$emp->registration}");
    }
    expect(Employee::query()->withoutGlobalScope(BranchScope::class)->whereNotNull('user_id')->count())->toBe(3);
});

it('db:seed 2× não duplica nem altera nada (contagens e audit iguais)', function () {
    $this->seed(DatabaseSeeder::class);
    $first = tableCounts();
    $equipmentsUpdatedAt = DB::table('equipments')->orderBy('id')->pluck('updated_at', 'id')->all();

    $this->seed(DatabaseSeeder::class);

    expect(tableCounts())->toBe($first)
        ->and(DB::table('equipments')->orderBy('id')->pluck('updated_at', 'id')->all())->toBe($equipmentsUpdatedAt);
});

it('convive com dados existentes: não altera registros que já estavam no banco nem colide com eles', function () {
    $branch = Branch::factory()->create(['code' => 'TST-01', 'name' => 'Filial teste']);
    $family = EquipmentFamily::factory()->create(['code' => 'TST-CAM']);
    $eq = Equipment::factory()->create(['code' => 'TST-EQ01', 'branch_id' => $branch->id, 'family_id' => $family->id]);
    $user = User::factory()->admin()->create(['username' => 'rickseptimus']);
    $before = [DB::table('branches')->where('id', $branch->id)->first(), DB::table('equipments')->where('id', $eq->id)->first(), DB::table('users')->where('id', $user->id)->first()];

    // o admin do seed é um usuário que já existe: nada muda nele
    config(['seed.admin.username' => 'rickseptimus']);
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $after = [DB::table('branches')->where('id', $branch->id)->first(), DB::table('equipments')->where('id', $eq->id)->first(), DB::table('users')->where('id', $user->id)->first()];
    expect($after)->toEqual($before);
    expect(User::query()->where('role', Role::Admin->value)->where('username', 'not like', 'demo.%')->count())->toBe(1);
});

it('em production o DemoSeeder não roda (nem pelo DatabaseSeeder nem chamado direto); parâmetros e admin rodam', function () {
    $this->app['env'] = 'production';

    // em production o db:seed pede confirmação: --force, como o make seed
    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
    $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])->assertSuccessful();

    expect(Branch::query()->where('code', 'like', 'DEMO-%')->count())->toBe(0)
        ->and(Equipment::query()->withoutGlobalScope(BranchScope::class)->count())->toBe(0)
        ->and(User::query()->where('username', 'like', 'demo.%')->count())->toBe(0)
        ->and(User::query()->where('username', 'admin.seed')->exists())->toBeTrue()
        ->and(Setting::query()->where('scope_type', 'global')->count())->toBe(4);
});

it('DemoSeeder: SEED_DEMO_PASSWORD ausente ou fora da política → mensagem clara, nada criado', function (string $password, string $message) {
    config(['seed.demo_password' => $password]);

    expect(fn () => $this->seed(DemoSeeder::class))->toThrow(SeedInputException::class, $message);
    expect(Branch::query()->count())->toBe(0);
})->with([
    'ausente' => ['', 'SEED_DEMO_PASSWORD'],
    'change-me' => ['change-me', 'SEED_DEMO_PASSWORD inválida'],
]);

it('login por username com cada perfil demo; me.permissions diferente por perfil', function () {
    $this->seed(DatabaseSeeder::class);

    $permissions = [];
    foreach (array_keys(DemoSeeder::USERS) as $username) {
        $token = api('POST', 'auth/login', ['username' => $username, 'password' => SEED_DEMO_PASSWORD, 'device_name' => 'pest'])
            ->assertOk()->assertJsonPath('data.user.username', $username)->json('data.token');
        $permissions[$username] = api('GET', 'auth/me', token: $token)->assertOk()->json('data.permissions');
    }

    expect(collect($permissions)->map(fn ($p) => json_encode($p))->unique()->count())->toBe(4);
    expect($permissions['demo.admin'])->toBe(config('rbac.roles.admin'))
        ->and($permissions['demo.operador'])->toBe(config('rbac.roles.operator'));
});

it('nenhuma senha literal nos seeders nem na config de seed', function () {
    $files = [...glob(database_path('seeders/*.php')), config_path('seed.php')];
    foreach ($files as $file) {
        // atribuição de senha com string literal: 'password' => '...'
        expect(preg_match("/'password'\\s*=>\\s*'[^']+'/", file_get_contents($file)))->toBe(0, basename($file));
    }
});
