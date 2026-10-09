<?php

namespace Database\Factories;

use App\Models\EquipmentFamily;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquipmentFamily>
 */
class EquipmentFamilyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('FAM-####??'),
            'name' => 'Família '.fake()->words(2, true),
            'category' => fake()->randomElement(EquipmentFamily::CATEGORIES),
            'criticality' => 'medium',
            'preventive_lead_pct' => 90,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
