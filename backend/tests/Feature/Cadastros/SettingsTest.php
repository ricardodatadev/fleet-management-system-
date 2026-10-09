<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\EquipmentFamily;
use App\Models\Setting;
use App\Support\Settings\SettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    $this->token = bearer($this->admin);
    $this->x = Branch::factory()->create(['code' => 'X', 'name' => 'Filial X']);
    $this->y = Branch::factory()->create(['code' => 'Y', 'name' => 'Filial Y']);
    $this->family = EquipmentFamily::factory()->create(['code' => 'CAM', 'name' => 'Caminhões']);
});

function putSetting(string $key, string $scopeType, ?int $scopeId, mixed $value, ?string $token = null): TestResponse
{
    return api('PUT', "settings/{$key}", ['scope_type' => $scopeType, 'scope_id' => $scopeId, 'value' => $value], $token ?? test()->token);
}

function effective(string $query, ?string $token = null): TestResponse
{
    return api('GET', "settings/effective?{$query}", token: $token ?? test()->token);
}

function settingLogs(): array
{
    return AuditLog::query()->whereIn('action', ['setting_changed', 'setting_removed'])->orderBy('id')->get()
        ->map(fn ($l) => [$l->action, $l->old_values, $l->new_values, $l->metadata])->all();
}

it('definitions: as 4 chaves RN-001..004 com rótulo, tipo, valores, default e escopos; RN-002 marcada como placeholder (D14)', function () {
    $defs = collect(api('GET', 'settings/definitions', token: $this->token)->assertOk()->json('data'))->keyBy('key');

    expect($defs->keys()->all())->toBe(['preventive.dispatch_mode', 'workorder.block_close_without_labor', 'stock.allow_issue_with_fiscal_pending', 'warranty.alert_mode']);
    expect($defs['preventive.dispatch_mode'])->toMatchArray(['rule' => 'RN-001', 'type' => 'enum', 'default' => 'suggestion', 'scopes' => ['global', 'family'], 'placeholder' => false])
        ->and(array_column($defs['preventive.dispatch_mode']['values'], 'value'))->toBe(['automatic', 'suggestion']);
    expect($defs['workorder.block_close_without_labor'])->toMatchArray(['rule' => 'RN-002', 'type' => 'bool', 'values' => null, 'default' => false, 'scopes' => ['global', 'branch'], 'placeholder' => true]);
    expect($defs['stock.allow_issue_with_fiscal_pending'])->toMatchArray(['rule' => 'RN-003', 'type' => 'bool', 'default' => true, 'scopes' => ['global', 'branch']]);
    expect($defs['warranty.alert_mode'])->toMatchArray(['rule' => 'RN-004', 'type' => 'enum', 'default' => 'warning', 'scopes' => ['global', 'branch', 'family']])
        ->and(array_column($defs['warranty.alert_mode']['values'], 'value'))->toBe(['warning', 'hard_block']);
    foreach ($defs as $def) {
        expect($def['label'])->toBeString()->not->toBeEmpty();
    }
});

it('precedência family > branch > global > default, com a origem', function () {
    $key = 'warranty.alert_mode';
    $q = "key={$key}&branch_id={$this->x->id}&family_id={$this->family->id}";

    effective($q)->assertOk()->assertJsonPath('data', ['key' => $key, 'value' => 'warning', 'source' => 'default']);

    putSetting($key, 'global', null, 'hard_block')->assertOk();
    effective($q)->assertJsonPath('data.value', 'hard_block')->assertJsonPath('data.source', ['scope_type' => 'global', 'scope_id' => null]);

    putSetting($key, 'branch', $this->x->id, 'warning')->assertOk();
    effective($q)->assertJsonPath('data.value', 'warning')->assertJsonPath('data.source', ['scope_type' => 'branch', 'scope_id' => $this->x->id]);

    putSetting($key, 'family', $this->family->id, 'hard_block')->assertOk();
    effective($q)->assertJsonPath('data.value', 'hard_block')->assertJsonPath('data.source', ['scope_type' => 'family', 'scope_id' => $this->family->id]);

    // sem a família no contexto, vale a filial; em outra filial, vale o global
    effective("key={$key}&branch_id={$this->x->id}")->assertJsonPath('data.source.scope_type', 'branch');
    effective("key={$key}&branch_id={$this->y->id}")->assertJsonPath('data.source.scope_type', 'global');
    effective("key={$key}")->assertJsonPath('data.source.scope_type', 'global');
});

it('resolve só considera os escopos permitidos da chave (override fora do registry é ignorado)', function () {
    // preventive.dispatch_mode não permite branch: um registro gravado direto no banco não entra na resolução
    Setting::query()->create(['key' => 'preventive.dispatch_mode', 'scope_type' => 'branch', 'scope_id' => $this->x->id, 'value' => 'automatic']);

    effective("key=preventive.dispatch_mode&branch_id={$this->x->id}")->assertJsonPath('data', ['key' => 'preventive.dispatch_mode', 'value' => 'suggestion', 'source' => 'default']);
    expect(app(SettingsService::class)->resolve('preventive.dispatch_mode', $this->x->id, null)['source'])->toBe('default');
});

it('bool sai como boolean JSON; default e override', function () {
    $key = 'stock.allow_issue_with_fiscal_pending';
    expect(effective("key={$key}")->json('data.value'))->toBeTrue();

    putSetting($key, 'branch', $this->x->id, false)->assertOk()->assertJsonPath('data.value', false);
    expect(effective("key={$key}&branch_id={$this->x->id}")->getContent())->toContain('"value":false');
});

it('PUT: chave inexistente, escopo não permitido, valor fora do tipo e scope_id inválido → 422', function (string $key, string $scopeType, mixed $scopeId, mixed $value, string $field) {
    $scopeId = match ($scopeId) {
        'X' => $this->x->id,
        'FAM' => $this->family->id,
        'DELETED_BRANCH' => tap(Branch::factory()->create())->delete()->id,
        'DELETED_FAMILY' => tap(EquipmentFamily::factory()->create())->delete()->id,
        default => $scopeId,
    };

    putSetting($key, $scopeType, $scopeId, $value)->assertStatus(422)->assertJsonValidationErrors([$field], 'errors');
    expect(Setting::query()->count())->toBe(0);
})->with([
    'chave inexistente' => ['nao.existe', 'global', null, true, 'key'],
    'escopo não permitido (branch em RN-001)' => ['preventive.dispatch_mode', 'branch', 'X', 'automatic', 'scope_type'],
    'escopo não permitido (family em RN-003)' => ['stock.allow_issue_with_fiscal_pending', 'family', 'FAM', true, 'scope_type'],
    'escopo fora da lista' => ['warranty.alert_mode', 'equipment', 'X', 'warning', 'scope_type'],
    'valor fora do enum' => ['warranty.alert_mode', 'global', null, 'block', 'value'],
    'bool como string' => ['stock.allow_issue_with_fiscal_pending', 'global', null, 'true', 'value'],
    'bool como número' => ['stock.allow_issue_with_fiscal_pending', 'global', null, 1, 'value'],
    'enum como boolean' => ['warranty.alert_mode', 'global', null, true, 'value'],
    'valor nulo' => ['warranty.alert_mode', 'global', null, null, 'value'],
    'global com scope_id' => ['warranty.alert_mode', 'global', 'X', 'warning', 'scope_id'],
    'branch sem scope_id' => ['warranty.alert_mode', 'branch', null, 'warning', 'scope_id'],
    'filial inexistente' => ['warranty.alert_mode', 'branch', 999999, 'warning', 'scope_id'],
    'filial excluída' => ['warranty.alert_mode', 'branch', 'DELETED_BRANCH', 'warning', 'scope_id'],
    'família excluída' => ['warranty.alert_mode', 'family', 'DELETED_FAMILY', 'warning', 'scope_id'],
]);

it('PUT é upsert idempotente: mesmo valor não cria linha nem audit; mudança audita setting_changed com old/new e escopo', function () {
    $key = 'warranty.alert_mode';
    putSetting($key, 'branch', $this->x->id, 'hard_block')->assertOk()
        ->assertJsonPath('data.scope', ['id' => $this->x->id, 'code' => 'X', 'name' => 'Filial X'])
        ->assertJsonPath('data.updated_by', ['id' => $this->admin->id, 'name' => $this->admin->name]);
    putSetting($key, 'branch', $this->x->id, 'hard_block')->assertOk();
    putSetting($key, 'branch', $this->x->id, 'warning')->assertOk()->assertJsonPath('data.value', 'warning');

    expect(Setting::query()->count())->toBe(1);
    expect(settingLogs())->toBe([
        ['setting_changed', null, ['value' => 'hard_block'], ['key' => $key, 'scope_id' => $this->x->id, 'scope_type' => 'branch']],
        ['setting_changed', ['value' => 'hard_block'], ['value' => 'warning'], ['key' => $key, 'scope_id' => $this->x->id, 'scope_type' => 'branch']],
    ]);
    $log = AuditLog::query()->where('action', 'setting_changed')->latest('id')->first();
    expect($log->actor_id)->toBe($this->admin->id)->and(AuditLog::aliasFor($log->auditable_type))->toBe('setting');
});

it('unicidade (key, scope_type, scope_id) no banco, inclusive no global (scope_id NULL); CHECK global ⇔ scope_id nulo', function (array $first, array $second) {
    DB::table('settings')->insert([...$first, 'key' => 'warranty.alert_mode', 'value' => '"warning"']);

    expect(fn () => DB::transaction(fn () => DB::table('settings')->insert([...$second, 'key' => 'warranty.alert_mode', 'value' => '"hard_block"'])))
        ->toThrow(QueryException::class);
})->with([
    'global duplicado' => [['scope_type' => 'global', 'scope_id' => null], ['scope_type' => 'global', 'scope_id' => null]],
    'filial duplicada' => [['scope_type' => 'branch', 'scope_id' => 1], ['scope_type' => 'branch', 'scope_id' => 1]],
    'global com scope_id (CHECK)' => [['scope_type' => 'branch', 'scope_id' => 2], ['scope_type' => 'global', 'scope_id' => 5]],
    'branch sem scope_id (CHECK)' => [['scope_type' => 'branch', 'scope_id' => 3], ['scope_type' => 'branch', 'scope_id' => null]],
    'scope_type fora da lista (CHECK)' => [['scope_type' => 'family', 'scope_id' => 4], ['scope_type' => 'equipment', 'scope_id' => 4]],
]);

it('o mesmo scope_id em escopos diferentes e chaves diferentes no mesmo escopo convivem', function () {
    DB::table('settings')->insert([
        ['key' => 'warranty.alert_mode', 'scope_type' => 'branch', 'scope_id' => 1, 'value' => '"warning"'],
        ['key' => 'warranty.alert_mode', 'scope_type' => 'family', 'scope_id' => 1, 'value' => '"warning"'],
        ['key' => 'stock.allow_issue_with_fiscal_pending', 'scope_type' => 'branch', 'scope_id' => 1, 'value' => 'true'],
    ]);

    expect(Setting::query()->count())->toBe(3);
});

it('DELETE: global não é removível (422); override inexistente → 404; remoção audita setting_removed e volta a herdar', function () {
    $key = 'warranty.alert_mode';
    putSetting($key, 'global', null, 'hard_block')->assertOk();
    putSetting($key, 'family', $this->family->id, 'warning')->assertOk();

    api('DELETE', "settings/{$key}?scope_type=global", token: $this->token)->assertStatus(422)->assertJsonPath('errors.scope_type.0', __('api.setting_global_not_removable'));
    api('DELETE', "settings/{$key}?scope_type=branch&scope_id={$this->x->id}", token: $this->token)->assertNotFound();
    api('DELETE', "settings/{$key}?scope_type=family", token: $this->token)->assertStatus(422)->assertJsonValidationErrors(['scope_id'], 'errors');
    api('DELETE', 'settings/nao.existe?scope_type=family&scope_id=1', token: $this->token)->assertStatus(422)->assertJsonValidationErrors(['key'], 'errors');

    api('DELETE', "settings/{$key}?scope_type=family&scope_id={$this->family->id}", token: $this->token)->assertOk()->assertJsonPath('message', __('api.setting_removed'));
    effective("key={$key}&family_id={$this->family->id}")->assertJsonPath('data.source.scope_type', 'global');
    expect(Setting::query()->count())->toBe(1);

    $removed = collect(settingLogs())->last();
    expect($removed)->toBe(['setting_removed', ['value' => 'warning'], null, ['key' => $key, 'scope_id' => $this->family->id, 'scope_type' => 'family']]);
});

it('GET /settings: filtros key, scope_type e scope_id; scope_id exige scope_type; escopo embutido', function () {
    putSetting('warranty.alert_mode', 'global', null, 'warning');
    putSetting('warranty.alert_mode', 'branch', $this->x->id, 'hard_block');
    putSetting('stock.allow_issue_with_fiscal_pending', 'branch', $this->x->id, false);
    putSetting('preventive.dispatch_mode', 'family', $this->family->id, 'automatic');

    $rows = fn (string $query) => collect(api('GET', "settings?{$query}", token: $this->token)->assertOk()->json('data'))
        ->map(fn ($r) => $r['key'].'@'.$r['scope_type'].($r['scope_id'] ? ':'.$r['scope_id'] : ''))->all();

    expect($rows(''))->toHaveCount(4);
    expect($rows('key=warranty.alert_mode'))->toBe(['warranty.alert_mode@branch:'.$this->x->id, 'warranty.alert_mode@global']);
    expect($rows("scope_type=branch&scope_id={$this->x->id}"))->toBe(['stock.allow_issue_with_fiscal_pending@branch:'.$this->x->id, 'warranty.alert_mode@branch:'.$this->x->id]);
    expect($rows('scope_type=family'))->toBe(['preventive.dispatch_mode@family:'.$this->family->id]);
    expect(api('GET', 'settings?scope_type=family', token: $this->token)->json('data.0.scope'))->toBe(['id' => $this->family->id, 'code' => 'CAM', 'name' => 'Caminhões']);

    api('GET', "settings?scope_id={$this->x->id}", token: $this->token)->assertStatus(422)->assertJsonValidationErrors(['scope_type'], 'errors');
    api('GET', 'settings?key=nao.existe', token: $this->token)->assertStatus(422);
    api('GET', 'settings?scope_type=equipment', token: $this->token)->assertStatus(422);
});

it('effective: key obrigatória e do registry; branch_id/family_id inexistentes ou excluídos → 422', function () {
    $deletedBranch = tap(Branch::factory()->create())->delete();
    $deletedFamily = tap(EquipmentFamily::factory()->create())->delete();

    effective('')->assertStatus(422)->assertJsonValidationErrors(['key'], 'errors');
    effective('key=nao.existe')->assertStatus(422)->assertJsonPath('errors.key.0', __('api.setting_unknown_key', ['key' => 'nao.existe']));
    effective('key=warranty.alert_mode&branch_id=999999')->assertStatus(422)->assertJsonValidationErrors(['branch_id'], 'errors');
    effective("key=warranty.alert_mode&branch_id={$deletedBranch->id}")->assertStatus(422)->assertJsonValidationErrors(['branch_id'], 'errors');
    effective("key=warranty.alert_mode&family_id={$deletedFamily->id}")->assertStatus(422)->assertJsonValidationErrors(['family_id'], 'errors');
});

it('permissões: A grava; L só lê (403 no PUT/DELETE); M e O → 403 em tudo', function () {
    putSetting('warranty.alert_mode', 'family', $this->family->id, 'hard_block')->assertOk();
    $leader = bearer(userWithRole(Role::Leader, $this->x));

    api('GET', 'settings/definitions', token: $leader)->assertOk();
    putSetting('warranty.alert_mode', 'branch', $this->x->id, 'warning', $leader)->assertForbidden();
    api('DELETE', "settings/warranty.alert_mode?scope_type=family&scope_id={$this->family->id}", token: $leader)->assertForbidden();
    expect(Setting::query()->count())->toBe(1);

    foreach ([Role::Mechanic, Role::Operator] as $role) {
        $token = bearer(userWithRole($role, $this->x));
        api('GET', 'settings/definitions', token: $token)->assertForbidden();
        api('GET', 'settings', token: $token)->assertForbidden();
        effective('key=warranty.alert_mode', $token)->assertForbidden();
    }
});

it('escopo do líder: effective/settings de outra filial → 403; própria filial e qualquer família → 200; lista sem as outras filiais', function () {
    putSetting('warranty.alert_mode', 'branch', $this->x->id, 'hard_block');
    putSetting('warranty.alert_mode', 'branch', $this->y->id, 'hard_block');
    putSetting('warranty.alert_mode', 'family', $this->family->id, 'warning');
    putSetting('warranty.alert_mode', 'global', null, 'warning');
    $leader = bearer(userWithRole(Role::Leader, $this->x));

    effective("key=warranty.alert_mode&branch_id={$this->y->id}", $leader)->assertForbidden();
    effective("key=warranty.alert_mode&branch_id={$this->x->id}", $leader)->assertOk()->assertJsonPath('data.source.scope_id', $this->x->id);
    effective("key=warranty.alert_mode&family_id={$this->family->id}", $leader)->assertOk()->assertJsonPath('data.source.scope_type', 'family');
    effective("key=warranty.alert_mode&branch_id={$this->x->id}&family_id={$this->family->id}", $leader)->assertOk();

    api('GET', "settings?scope_type=branch&scope_id={$this->y->id}", token: $leader)->assertForbidden();
    api('GET', "settings?scope_type=branch&scope_id={$this->x->id}", token: $leader)->assertOk();
    api('GET', 'settings?scope_type=family', token: $leader)->assertOk();

    $seen = collect(api('GET', 'settings', token: $leader)->assertOk()->json('data'))->map(fn ($r) => $r['scope_type'].':'.$r['scope_id'])->sort()->values()->all();
    expect($seen)->toBe(collect(['branch:'.$this->x->id, 'family:'.$this->family->id, 'global:'])->sort()->values()->all());
    // admin vê todos
    expect(api('GET', 'settings', token: $this->token)->json('meta.total'))->toBe(4);
});

it('sem motor de regra: as chaves e o SettingsService só são usados pela API de parâmetros', function () {
    // a própria API de parâmetros (service, controller, request, resource e model)
    $allowed = [
        'app/Support/Settings/SettingsService.php', 'app/Http/Controllers/Api/V1/SettingController.php',
        'app/Http/Requests/Settings/SettingRequest.php', 'app/Http/Resources/SettingResource.php', 'app/Models/Setting.php',
    ];
    $offending = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())))
        ->filter(fn (SplFileInfo $f) => $f->isFile() && $f->getExtension() === 'php')
        ->map(fn (SplFileInfo $f) => str_replace(base_path().'/', '', $f->getPathname()))
        ->reject(fn (string $path) => in_array($path, $allowed, true))
        ->filter(fn (string $path) => preg_match('/SettingsService|settings_registry|preventive\.dispatch_mode|block_close_without_labor|allow_issue_with_fiscal_pending|warranty\.alert_mode/', file_get_contents(base_path($path))) === 1)
        ->values()->all();

    expect($offending)->toBe([]);
});
