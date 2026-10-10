<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** Senha padrão das fábricas (atende à política: 10+ caracteres, maiúscula, minúscula e número). */
    public const PASSWORD = 'Senha12345';

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // formato da D.2 v1.9 (UsernameGenerator::REGEX)
            'username' => fake()->unique()->bothify('user########'),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make(self::PASSWORD),
            'role' => Role::Operator,
            'branch_id' => Branch::factory(),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function role(Role $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    /** Admin sem filial (enxerga todas). */
    public function admin(): static
    {
        return $this->state(fn () => ['role' => Role::Admin, 'branch_id' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
