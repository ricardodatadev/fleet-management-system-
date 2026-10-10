<?php

use App\Enums\Role;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\EquipmentFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** 1.000 equipamentos em 50 filiais, 3 status e responsáveis variados (inserção em lote). */
function seedEquipments(int $total = 1000, int $branches = 50): array
{
    $branchIds = Branch::factory()->count($branches)->create()->pluck('id')->all();
    $familyIds = EquipmentFamily::factory()->count(5)->create()->pluck('id')->all();
    $costCenterId = CostCenter::factory()->global()->create()->id;
    $employeeIds = collect($branchIds)->mapWithKeys(fn ($b) => [$b => Employee::factory()->create(['branch_id' => $b])->id])->all();
    $statuses = ['active', 'inactive', 'disposed'];
    $now = now();

    $rows = [];
    for ($i = 1; $i <= $total; $i++) {
        $branchId = $branchIds[$i % $branches];
        $rows[] = [
            'code' => sprintf('PERF-%05d', $i), 'name' => "Equipamento {$i}", 'plate' => sprintf('PRF%04d', $i),
            'family_id' => $familyIds[$i % 5], 'branch_id' => $branchId, 'cost_center_id' => $costCenterId,
            'responsible_employee_id' => $i % 3 === 0 ? $employeeIds[$branchId] : null,
            'status' => $statuses[$i % 3], 'year' => 2000 + $i % 25, 'odometer_km' => $i * 10.5, 'hour_meter' => $i,
            'created_at' => $now, 'updated_at' => $now,
        ];
    }
    foreach (array_chunk($rows, 250) as $chunk) {
        DB::table('equipments')->insert($chunk);
    }
    DB::statement('ANALYZE equipments');

    return $branchIds;
}

it('EXPLAIN do filtro branch_id + status usa o índice (branch_id, status)', function () {
    $branchIds = seedEquipments();

    $plan = collect(DB::select(
        "EXPLAIN SELECT * FROM equipments WHERE branch_id = ? AND status = 'active' AND deleted_at IS NULL ORDER BY code LIMIT 15",
        [$branchIds[7]],
    ))->map(fn ($row) => (array) $row)->flatten()->implode("\n");

    fwrite(STDERR, PHP_EOL.'[explain branch_id+status]'.PHP_EOL.$plan.PHP_EOL);
    expect($plan)->toContain('equipments_branch_id_status_idx')->not->toContain('Seq Scan on equipments');
});

it('lista de 15 itens com 1.000 registros responde em < 300 ms (admin e líder com escopo), sem N+1', function () {
    $branchIds = seedEquipments();
    $admin = bearer(userWithRole(Role::Admin));
    $leader = bearer(userWithRole(Role::Leader, Branch::query()->findOrFail($branchIds[3])));

    $measure = function (string $uri, string $token): array {
        api('GET', $uri, token: $token)->assertOk(); // aquecimento (autoload, opcache, plano)
        $times = [];
        for ($i = 0; $i < 7; $i++) {
            $start = hrtime(true);
            $res = api('GET', $uri, token: $token)->assertOk();
            $times[] = (hrtime(true) - $start) / 1e6;
        }
        sort($times);

        return [$times[3], $res]; // mediana
    };

    [$adminMs, $adminRes] = $measure('equipments?per_page=15', $admin);
    [$filterMs] = $measure("equipments?per_page=15&branch_id={$branchIds[7]}&status=active&sort=-year", $admin);
    [$leaderMs, $leaderRes] = $measure('equipments?per_page=15&q=equip', $leader);

    fwrite(STDERR, sprintf(PHP_EOL.'[lista 15/1000] admin %.1f ms · admin filtro branch+status %.1f ms · líder com escopo e q %.1f ms'.PHP_EOL, $adminMs, $filterMs, $leaderMs));
    expect($adminRes->json('meta.total'))->toBe(1000)->and($adminRes->json('data'))->toHaveCount(15);
    expect($leaderRes->json('meta.total'))->toBe(20);
    expect(max($adminMs, $filterMs, $leaderMs))->toBeLessThan(300);

    // sem N+1: o número de queries não cresce com per_page (as duas páginas têm itens com responsável;
    // com 1 item sem responsável o Laravel pula o eager load de colaboradores e faz uma query a menos)
    $queries = function (int $perPage) use ($admin): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        api('GET', "equipments?per_page={$perPage}", token: $admin)->assertOk();

        return collect(DB::getQueryLog())->pluck('query')->all();
    };
    // Relógio parado: o Sanctum só regrava last_used_at do token quando o segundo muda (query aleatória).
    $this->freezeSecond();
    $queries(1); // aquecimento
    $small = $queries(30);
    $large = $queries(60);
    expect(count($large))->toBe(count($small), "queries a mais:\n".implode("\n", array_diff($large, $small)));
});
