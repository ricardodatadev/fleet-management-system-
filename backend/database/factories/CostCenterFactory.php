<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CostCenter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CostCenter>
 */
class CostCenterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('CC-####??'),
            'name' => 'Centro de custo '.fake()->words(2, true),
            'branch_id' => Branch::factory(),
            'is_active' => true,
        ];
    }

    /** Sem filial (visível a todas). */
    public function global(): static
    {
        return $this->state(fn () => ['branch_id' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
