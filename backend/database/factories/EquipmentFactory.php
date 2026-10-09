<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Equipment;
use App\Models\EquipmentFamily;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('EQ-#####??'),
            'name' => 'Equipamento '.fake()->words(2, true),
            'family_id' => EquipmentFamily::factory(),
            'branch_id' => Branch::factory(),
            // centro de custo sem filial: vale para qualquer filial do equipamento
            'cost_center_id' => fn () => CostCenter::factory()->global()->create()->id,
            'status' => 'active',
        ];
    }

    /** Na filial informada (centro de custo sem filial, compatível). */
    public function inBranch(Branch $branch): static
    {
        return $this->state(fn () => ['branch_id' => $branch->id]);
    }
}
