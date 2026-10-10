<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\EquipmentFamily;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Dados de demonstração (SOMENTE local/testing): 2 filiais, 3 centros de custo, 5 famílias, 32
 * equipamentos, colaboradores coerentes com a consistência de filial e 1 usuário por perfil com a senha
 * SEED_DEMO_PASSWORD. Tudo com o prefixo DEMO e procurado pelo código/username antes de criar: rodar de
 * novo não duplica nem altera nada (inclusive registros já existentes de outras origens).
 */
class DemoSeeder extends Seeder
{
    public const ENVIRONMENTS = ['local', 'testing'];

    public const EQUIPMENTS = 32;

    /** Usuários demo (um por perfil) e o colaborador vinculado a cada um (o admin não tem). */
    public const USERS = [
        'demo.admin' => ['role' => Role::Admin, 'name' => 'Ana Admin (demo)', 'job_type' => null],
        'demo.lider' => ['role' => Role::Leader, 'name' => 'Lucas Líder (demo)', 'job_type' => 'leader'],
        'demo.mecanico' => ['role' => Role::Mechanic, 'name' => 'Marcos Mecânico (demo)', 'job_type' => 'mechanic'],
        'demo.operador' => ['role' => Role::Operator, 'name' => 'Otávio Operador (demo)', 'job_type' => 'driver'],
    ];

    public function run(): void
    {
        if (! app()->environment(self::ENVIRONMENTS)) {
            $this->command?->warn('DemoSeeder recusado: só roda em '.implode('/', self::ENVIRONMENTS).'.');

            return;
        }
        $password = $this->demoPassword();

        DB::transaction(function () use ($password) {
            [$main, $north] = $this->branches();
            $costCenters = $this->costCenters($main, $north);
            $families = $this->families();
            $employees = $this->usersAndEmployees($password, $main, $north);
            $this->equipments([$main, $north], $costCenters, $families, $employees);
        });

        $this->command?->info('Dados de demonstração prontos (prefixo DEMO; registros existentes mantidos).');
    }

    private function demoPassword(): string
    {
        $password = config('seed.demo_password');
        if (blank($password)) {
            throw new SeedInputException('Defina SEED_DEMO_PASSWORD no .env (e repasse ao container): é a senha dos usuários de demonstração.');
        }
        $validator = Validator::make(['password' => $password], ['password' => [Password::defaults()]]);
        if ($validator->fails()) {
            throw new SeedInputException('SEED_DEMO_PASSWORD inválida: '.implode(' ', $validator->errors()->all()));
        }

        return $password;
    }

    /** @return array{0: Branch, 1: Branch} */
    private function branches(): array
    {
        return [
            $this->firstOrCreate(Branch::class, ['code' => 'DEMO-01'], ['name' => 'Matriz (demo)', 'type' => 'filial', 'city' => 'Goiânia', 'state' => 'GO']),
            $this->firstOrCreate(Branch::class, ['code' => 'DEMO-02'], ['name' => 'Garagem Norte (demo)', 'type' => 'garagem', 'city' => 'Anápolis', 'state' => 'GO']),
        ];
    }

    /** @return array<string, CostCenter> por filial ('main', 'north') e o sem filial ('global') */
    private function costCenters(Branch $main, Branch $north): array
    {
        return [
            'main' => $this->firstOrCreate(CostCenter::class, ['code' => 'DEMO-CC-01'], ['name' => 'Operação Matriz (demo)', 'branch_id' => $main->id]),
            'north' => $this->firstOrCreate(CostCenter::class, ['code' => 'DEMO-CC-02'], ['name' => 'Operação Norte (demo)', 'branch_id' => $north->id]),
            'global' => $this->firstOrCreate(CostCenter::class, ['code' => 'DEMO-CC-GL'], ['name' => 'Manutenção corporativa (demo)', 'branch_id' => null]),
        ];
    }

    /** @return list<EquipmentFamily> */
    private function families(): array
    {
        $rows = [
            ['DEMO-LEVE', 'Veículos leves (demo)', 'light_vehicle', 'low', 95, 1000, null, 15],
            ['DEMO-CAM', 'Caminhões (demo)', 'truck', 'high', 90, 500, 20, 7],
            ['DEMO-AGR', 'Máquinas agrícolas (demo)', 'agri_machine', 'critical', 85, null, 10, 5],
            ['DEMO-IMP', 'Implementos (demo)', 'implement', 'medium', 90, null, 25, 10],
            ['DEMO-APO', 'Apoio (demo)', 'support', 'low', 92.5, null, null, 30],
        ];

        return array_map(fn (array $r) => $this->firstOrCreate(EquipmentFamily::class, ['code' => $r[0]], [
            'name' => $r[1], 'category' => $r[2], 'criticality' => $r[3], 'preventive_lead_pct' => $r[4],
            'tolerance_km' => $r[5], 'tolerance_hours' => $r[6], 'tolerance_days' => $r[7],
        ]), $rows);
    }

    /**
     * Um usuário por perfil (o admin sem filial; os demais na Matriz) e colaboradores: os vinculados aos
     * usuários (mesma filial do usuário) e alguns sem usuário nas duas filiais.
     *
     * @return array<int, list<Employee>> colaboradores por branch_id
     */
    private function usersAndEmployees(string $password, Branch $main, Branch $north): array
    {
        $employees = [];
        $n = 0;
        foreach (self::USERS as $username => $u) {
            $user = User::withTrashed()->where('username', $username)->first()
                ?? User::query()->create([
                    'name' => $u['name'], 'username' => $username, 'email' => "{$username}@example.com", 'password' => $password,
                    'role' => $u['role'], 'branch_id' => $u['role'] === Role::Admin ? null : $main->id, 'is_active' => true,
                ]);
            if ($u['job_type'] !== null) {
                $n++;
                $employee = $this->employee(sprintf('DEMO-M%02d', $n), $u['name'], $u['job_type'], $main,
                    $user->trashed() || ($user->branch_id !== null && $user->branch_id !== $main->id) || $this->userLinked($user) ? null : $user->id);
                $employees[$main->id][] = $employee;
            }
        }

        foreach ([
            ['DEMO-M10', 'Diego Motorista (demo)', 'driver', $north],
            ['DEMO-M11', 'Nina Motorista (demo)', 'driver', $north],
            ['DEMO-M12', 'Pedro Mecânico (demo)', 'mechanic', $north],
            ['DEMO-M13', 'Rita Equipe Adm (demo)', 'admin_staff', $main],
            ['DEMO-M14', 'Sara Motorista (demo)', 'driver', $main],
        ] as [$registration, $name, $jobType, $branch]) {
            $employees[$branch->id][] = $this->employee($registration, $name, $jobType, $branch, null);
        }

        return $employees;
    }

    private function employee(string $registration, string $name, string $jobType, Branch $branch, ?int $userId): Employee
    {
        $typeFields = match ($jobType) {
            'driver' => ['cnh_number' => '0'.substr(md5($registration), 0, 10), 'cnh_category' => 'D', 'cnh_expires_at' => '2030-12-31'],
            'mechanic' => ['specialty' => 'Motor diesel', 'hourly_cost' => 85.5],
            default => [],
        };

        return $this->firstOrCreate(Employee::class, ['registration' => $registration], [
            'name' => $name, 'job_type' => $jobType, 'branch_id' => $branch->id, 'user_id' => $userId,
            'hired_at' => '2022-03-01', ...$typeFields,
        ]);
    }

    /** O usuário já tem colaborador não excluído (o vínculo é 1:1). */
    private function userLinked(User $user): bool
    {
        return Employee::query()->withoutGlobalScope(BranchScope::class)->where('user_id', $user->id)->exists();
    }

    /**
     * Equipamentos alternando as filiais, com centro de custo da filial ou sem filial e, em parte, um
     * responsável da mesma filial (consistência de filial da D.2).
     *
     * @param  array{0: Branch, 1: Branch}  $branches
     * @param  array<string, CostCenter>  $costCenters
     * @param  list<EquipmentFamily>  $families
     * @param  array<int, list<Employee>>  $employees
     */
    private function equipments(array $branches, array $costCenters, array $families, array $employees): void
    {
        $statuses = ['active', 'active', 'active', 'inactive', 'active', 'disposed'];
        for ($i = 1; $i <= self::EQUIPMENTS; $i++) {
            $branch = $branches[$i % 2];
            $family = $families[$i % count($families)];
            $costCenter = $i % 4 === 0 ? $costCenters['global'] : ($branch->is($branches[0]) ? $costCenters['main'] : $costCenters['north']);
            $staff = $employees[$branch->id] ?? [];
            $responsible = $i % 3 === 0 && $staff !== [] ? $staff[$i % count($staff)] : null;

            $this->firstOrCreate(Equipment::class, ['code' => sprintf('DEMO-EQ%02d', $i)], [
                'name' => sprintf('%s %02d', rtrim(str_replace('(demo)', '', $family->name)), $i),
                'family_id' => $family->id, 'branch_id' => $branch->id, 'cost_center_id' => $costCenter->id,
                'responsible_employee_id' => $responsible?->id,
                'plate' => in_array($family->category, ['light_vehicle', 'truck'], true) ? sprintf('DMO%04d', $i) : null,
                'manufacturer' => ['Volvo', 'Scania', 'John Deere', 'Randon', 'Toyota'][$i % 5],
                'year' => 2012 + $i % 13, 'status' => $statuses[$i % count($statuses)],
                'criticality_override' => $i % 7 === 0 ? 'critical' : null,
                'odometer_km' => $i * 12500.5, 'hour_meter' => $i * 310,
                'acquisition_date' => sprintf('%d-0%d-15', 2012 + $i % 13, 1 + $i % 9), 'acquisition_value' => 85000 + $i * 7350.25,
            ]);
        }
    }

    /**
     * Procura pelo código (só entre os não excluídos, como o UQ parcial) e cria se não houver; nunca
     * altera um registro existente.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $values
     * @return T
     */
    private function firstOrCreate(string $class, array $match, array $values): Model
    {
        return $class::query()->withoutGlobalScope(BranchScope::class)->where($match)->first()
            ?? $class::query()->create([...$match, ...$values]);
    }
}
