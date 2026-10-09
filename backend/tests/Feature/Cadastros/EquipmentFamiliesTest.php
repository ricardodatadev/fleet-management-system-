<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\EquipmentFamily;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = userWithRole(Role::Admin);
    $this->token = bearer($this->admin);
});

function familyPayload(array $overrides = []): array
{
    return ['code' => 'CAM-PESADO', 'name' => 'Caminhões pesados', 'category' => 'truck', ...$overrides];
}

/** Ações do audit da família, da mais antiga à mais nova. */
function familyLogs(int $id): array
{
    return AuditLog::query()->where('auditable_type', (new EquipmentFamily)->getMorphClass())->where('auditable_id', $id)->orderBy('id')->pluck('action')->all();
}

it('cria (201) com padrões criticality=medium e preventive_lead_pct=90, code normalizado e audit created', function () {
    $res = api('POST', 'equipment-families', familyPayload(['code' => ' cam-pesado ']), $this->token)->assertCreated();

    expect($res->json('data'))->toMatchArray([
        'code' => 'CAM-PESADO', 'name' => 'Caminhões pesados', 'category' => 'truck', 'criticality' => 'medium',
        'preventive_lead_pct' => 90, 'tolerance_km' => null, 'tolerance_hours' => null, 'tolerance_days' => null,
        'is_active' => true, 'deleted_at' => null,
    ]);
    expect(array_keys($res->json('data')))->toBe(['id', 'code', 'name', 'category', 'criticality', 'preventive_lead_pct', 'tolerance_km', 'tolerance_hours', 'tolerance_days', 'is_active', 'created_at', 'updated_at', 'deleted_at']);

    $log = AuditLog::query()->where('auditable_type', (new EquipmentFamily)->getMorphClass())->sole();
    expect($log->action)->toBe('created')->and($log->actor_id)->toBe($this->admin->id);
});

it('preventive_lead_pct sai como número JSON (90 e 87.5), nunca string; tolerâncias como int', function () {
    $id = api('POST', 'equipment-families', familyPayload(['preventive_lead_pct' => 87.5, 'tolerance_km' => 500, 'tolerance_hours' => 20, 'tolerance_days' => 0]), $this->token)->json('data.id');
    $default = EquipmentFamily::factory()->create(); // relido do banco: numeric(5,2) chega como "90.00"

    $show = api('GET', "equipment-families/{$id}", token: $this->token)->assertOk();
    expect($show->json('data.preventive_lead_pct'))->toBe(87.5)
        ->and($show->json('data.tolerance_km'))->toBe(500)->and($show->json('data.tolerance_hours'))->toBe(20)->and($show->json('data.tolerance_days'))->toBe(0);
    expect($show->getContent())->toContain('"preventive_lead_pct":87.5');

    $raw = api('GET', "equipment-families/{$default->id}", token: $this->token)->assertOk()->getContent();
    expect($raw)->toMatch('/"preventive_lead_pct":90(\.0)?[,}]/')->not->toContain('"preventive_lead_pct":"');
    expect(json_decode($raw, true)['data']['preventive_lead_pct'])->toEqual(90);

    expect(api('GET', 'equipment-families', token: $this->token)->getContent())->not->toContain('"preventive_lead_pct":"');
});

it('preventive_lead_pct fora de (0, 100] ou com mais de 2 casas → 422; 100 e 0.01 são aceitos', function (mixed $value, int $status) {
    api('POST', 'equipment-families', familyPayload(['preventive_lead_pct' => $value]), $this->token)->assertStatus($status);
})->with([
    'zero' => [0, 422], 'negativo' => [-5, 422], 'acima de 100' => [100.01, 422], '3 casas' => [50.123, 422], 'texto' => ['abc', 422], 'null' => [null, 422],
    'limite 100' => [100, 201], 'mínimo' => [0.01, 201],
]);

it('tolerâncias negativas ou não inteiras → 422; null e 0 são aceitos', function () {
    foreach (['tolerance_km', 'tolerance_hours', 'tolerance_days'] as $field) {
        api('POST', 'equipment-families', familyPayload([$field => -1]), $this->token)->assertStatus(422)->assertJsonValidationErrors([$field], 'errors');
        api('POST', 'equipment-families', familyPayload([$field => 1.5]), $this->token)->assertStatus(422)->assertJsonValidationErrors([$field], 'errors');
    }
    api('POST', 'equipment-families', familyPayload(['tolerance_km' => 0, 'tolerance_hours' => null, 'tolerance_days' => 0]), $this->token)->assertCreated();
});

it('category/criticality fora do enum, campos obrigatórios e code duplicado entre ativos → 422', function () {
    api('POST', 'equipment-families', [], $this->token)->assertStatus(422)->assertJsonValidationErrors(['code', 'name', 'category'], 'errors');
    api('POST', 'equipment-families', familyPayload(['category' => 'boat']), $this->token)->assertStatus(422)->assertJsonValidationErrors(['category'], 'errors');
    api('POST', 'equipment-families', familyPayload(['criticality' => 'extreme']), $this->token)->assertStatus(422)->assertJsonValidationErrors(['criticality'], 'errors');

    $family = EquipmentFamily::factory()->create(['code' => 'CAM-PESADO']);
    api('POST', 'equipment-families', familyPayload(['code' => 'cam-pesado']), $this->token)->assertStatus(422)->assertJsonValidationErrors(['code'], 'errors');
    api('PUT', "equipment-families/{$family->id}", ['code' => 'CAM-PESADO', 'name' => 'Igual'], $this->token)->assertOk();

    $family->delete();
    api('POST', 'equipment-families', familyPayload(), $this->token)->assertCreated();
});

it('CHECKs no banco rejeitam violação direta no SQL', function (string $column, mixed $value) {
    $insert = fn () => DB::table('equipment_families')->insert(['code' => 'SQL', 'name' => 'SQL', 'category' => 'truck', $column => $value]);

    expect(fn () => DB::transaction($insert))->toThrow(QueryException::class);
})->with([
    'preventive_lead_pct = 0' => ['preventive_lead_pct', 0],
    'preventive_lead_pct > 100' => ['preventive_lead_pct', 100.5],
    'tolerance_km < 0' => ['tolerance_km', -1],
    'tolerance_hours < 0' => ['tolerance_hours', -1],
    'tolerance_days < 0' => ['tolerance_days', -1],
    'category fora do enum' => ['category', 'boat'],
    'criticality fora do enum' => ['criticality', 'extreme'],
]);

it('o banco aplica os padrões da C.4 e aceita o limite 100', function () {
    DB::table('equipment_families')->insert(['code' => 'SQL', 'name' => 'SQL', 'category' => 'support']);
    DB::table('equipment_families')->insert(['code' => 'SQL2', 'name' => 'SQL', 'category' => 'support', 'preventive_lead_pct' => 100]);

    $row = DB::table('equipment_families')->where('code', 'SQL')->first();
    expect($row->criticality)->toBe('medium')->and((float) $row->preventive_lead_pct)->toBe(90.0)->and($row->is_active)->toBeTrue();
});

it('PUT e PATCH parciais, audit updated só com os campos alterados', function () {
    $family = EquipmentFamily::factory()->create(['name' => 'Antes', 'criticality' => 'low']);

    api('PUT', "equipment-families/{$family->id}", ['name' => 'Depois'], $this->token)->assertOk()->assertJsonPath('data.criticality', 'low');
    api('PATCH', "equipment-families/{$family->id}", ['criticality' => 'critical', 'preventive_lead_pct' => 92.5], $this->token)
        ->assertOk()->assertJsonPath('data.name', 'Depois')->assertJsonPath('data.preventive_lead_pct', 92.5);

    $updated = AuditLog::query()->where('auditable_id', $family->id)->where('auditable_type', (new EquipmentFamily)->getMorphClass())->where('action', 'updated')->orderBy('id')->get();
    expect($updated)->toHaveCount(2)
        ->and($updated[0]->old_values)->toBe(['name' => 'Antes'])->and($updated[0]->new_values)->toBe(['name' => 'Depois'])
        ->and(array_keys($updated[1]->new_values))->toEqualCanonicalizing(['criticality', 'preventive_lead_pct']);
});

it('lista: filtros category/criticality/is_active, q em code e name, sort só por code/name/category/criticality', function () {
    EquipmentFamily::factory()->create(['code' => 'B-TRK', 'name' => 'Caminhão', 'category' => 'truck', 'criticality' => 'high']);
    EquipmentFamily::factory()->create(['code' => 'A-AGR', 'name' => 'Trator', 'category' => 'agri_machine', 'criticality' => 'critical']);
    EquipmentFamily::factory()->inactive()->create(['code' => 'C-SUP', 'name' => 'Apoio', 'category' => 'support', 'criticality' => 'low']);
    EquipmentFamily::factory()->create(['code' => 'D-DEL', 'category' => 'truck'])->delete();

    $codes = fn (string $query) => api('GET', "equipment-families{$query}", token: $this->token)->assertOk()->json('data.*.code');

    expect($codes(''))->toBe(['A-AGR', 'B-TRK', 'C-SUP']);
    expect($codes('?category=truck'))->toBe(['B-TRK']);
    expect($codes('?criticality=critical'))->toBe(['A-AGR']);
    expect($codes('?is_active=0'))->toBe(['C-SUP']);
    expect($codes('?q=trat'))->toBe(['A-AGR']);
    expect($codes('?q=c-su'))->toBe(['C-SUP']);
    expect($codes('?sort=-code'))->toBe(['C-SUP', 'B-TRK', 'A-AGR']);
    expect($codes('?sort=name'))->toBe(['C-SUP', 'B-TRK', 'A-AGR']);
    expect($codes('?sort=category'))->toBe(['A-AGR', 'C-SUP', 'B-TRK']);
    expect($codes('?sort=-criticality'))->toBe(['C-SUP', 'B-TRK', 'A-AGR']); // texto: low > high > critical
    expect($codes('?with_trashed=1'))->toBe(['A-AGR', 'B-TRK', 'C-SUP', 'D-DEL']);

    foreach (['?sort=is_active', '?sort=created_at', '?sort=updated_at', '?sort=preventive_lead_pct', '?category=boat', '?criticality=x', '?per_page=101'] as $query) {
        api('GET', "equipment-families{$query}", token: $this->token)->assertStatus(422);
    }
});

it('M e L leem (sem escopo de filial); O → 403; só A escreve; with_trashed sem manage → 403', function () {
    $family = EquipmentFamily::factory()->create();
    $branch = Branch::factory()->create();

    foreach ([Role::Mechanic, Role::Leader] as $role) {
        $token = bearer(userWithRole($role, $branch));
        api('GET', 'equipment-families', token: $token)->assertOk();
        api('GET', "equipment-families/{$family->id}", token: $token)->assertOk();
        api('GET', 'equipment-families?with_trashed=1', token: $token)->assertForbidden();
        api('POST', 'equipment-families', familyPayload(), $token)->assertForbidden();
        api('DELETE', "equipment-families/{$family->id}", token: $token)->assertForbidden();
    }
    $operator = bearer(userWithRole(Role::Operator, $branch));
    api('GET', 'equipment-families', token: $operator)->assertForbidden();
});

it('excluir (soft) e restaurar com audit; excluída → 404 no show/update/delete; restore 409 com code reutilizado', function () {
    $family = EquipmentFamily::factory()->create(['code' => 'FAM-1']);

    api('DELETE', "equipment-families/{$family->id}", token: $this->token)->assertOk()->assertJsonPath('data', null);
    api('GET', "equipment-families/{$family->id}", token: $this->token)->assertNotFound();
    api('PATCH', "equipment-families/{$family->id}", ['name' => 'X'], $this->token)->assertNotFound();
    api('DELETE', "equipment-families/{$family->id}", token: $this->token)->assertNotFound();

    api('POST', "equipment-families/{$family->id}/restore", token: $this->token)->assertOk()->assertJsonPath('data.deleted_at', null);
    api('POST', "equipment-families/{$family->id}/restore", token: $this->token)->assertStatus(409);
    expect(familyLogs($family->id))->toBe(['created', 'deleted', 'restored']);

    $family->delete();
    EquipmentFamily::factory()->create(['code' => 'FAM-1']);
    api('POST', "equipment-families/{$family->id}/restore", token: $this->token)->assertStatus(409)->assertJsonPath('errors.code.0', __('api.restore_code_taken'));
});

it('dependentes: família sem equipamento é excluída normalmente (o 409 com equipamentos está no EquipmentsTest)', function () {
    $family = EquipmentFamily::factory()->create();

    expect($family->activeDependents())->toBe([]);
    expect(__('api.dependents.equipments'))->toBe('equipamentos');
    api('DELETE', "equipment-families/{$family->id}", token: $this->token)->assertOk(); // sem tabela de equipamentos, não quebra
});

it('/meta/enums devolve equipment_categories e criticalities', function () {
    api('GET', 'meta/enums', token: $this->token)->assertOk()
        ->assertJsonPath('data.enums.equipment_categories', ['light_vehicle', 'truck', 'agri_machine', 'implement', 'support'])
        ->assertJsonPath('data.enums.criticalities', ['low', 'medium', 'high', 'critical']);
});
