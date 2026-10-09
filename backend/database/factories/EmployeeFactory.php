<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'registration' => fake()->unique()->bothify('MAT-#####'),
            'name' => fake()->name(),
            'job_type' => 'leader',
            'branch_id' => Branch::factory(),
            'is_active' => true,
        ];
    }

    public function driver(): static
    {
        return $this->state(fn () => ['job_type' => 'driver', 'cnh_number' => fake()->numerify('###########'), 'cnh_category' => 'D', 'cnh_expires_at' => '2030-12-31']);
    }

    public function mechanic(): static
    {
        return $this->state(fn () => ['job_type' => 'mechanic', 'specialty' => 'Motor diesel', 'hourly_cost' => 85.5]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
