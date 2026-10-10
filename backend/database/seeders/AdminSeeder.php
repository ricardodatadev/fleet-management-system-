<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use App\Support\Users\UsernameGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Administrador inicial a partir de SEED_ADMIN_USERNAME/EMAIL/PASSWORD (todos os ambientes). Falha com
 * mensagem clara se alguma faltar. Idempotente: se o username já existe, nada muda (nem a senha).
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $config = config('seed.admin');
        $missing = array_keys(array_filter([
            'SEED_ADMIN_USERNAME' => blank($config['username']),
            'SEED_ADMIN_EMAIL' => blank($config['email']),
            'SEED_ADMIN_PASSWORD' => blank($config['password']),
        ]));
        if ($missing !== []) {
            throw new SeedInputException('Defina no .env (e repasse ao container): '.implode(', ', $missing).'. O administrador inicial vem dessas variáveis.');
        }

        $username = mb_strtolower(trim($config['username']));
        $email = mb_strtolower(trim($config['email']));

        if (User::withTrashed()->where('username', $username)->exists()) {
            $this->command?->info("Administrador '{$username}' já existe: mantido (a senha não é alterada).");

            return;
        }

        $validator = Validator::make(
            ['username' => $username, 'email' => $email, 'password' => $config['password']],
            [
                'username' => ['regex:'.UsernameGenerator::PATTERN],
                'email' => ['email', 'max:190'],
                'password' => [Password::defaults()],
            ],
            ['username.regex' => 'SEED_ADMIN_USERNAME: '.__('validation.custom.username.regex')],
        );
        if ($validator->fails()) {
            throw new SeedInputException('Variáveis do administrador inválidas: '.implode(' ', $validator->errors()->all()));
        }
        if (User::query()->where('email', $email)->exists()) {
            throw new SeedInputException("SEED_ADMIN_EMAIL '{$email}' já pertence a outro usuário ativo; use outro e-mail ou o username dele em SEED_ADMIN_USERNAME.");
        }

        DB::transaction(fn () => User::query()->create([
            'name' => $config['name'], 'username' => $username, 'email' => $email,
            'password' => $config['password'], 'role' => Role::Admin, 'branch_id' => null, 'is_active' => true,
        ]));
        $this->command?->info("Administrador '{$username}' criado.");
    }
}
