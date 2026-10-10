<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeders da Fase 1 (F1-17). Todos idempotentes: rodar `db:seed` de novo não duplica nem altera o que já
 * existe. Parâmetros e administrador em todos os ambientes; dados de demonstração só em local/testing.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([SettingsSeeder::class, AdminSeeder::class]);

        if (app()->environment(DemoSeeder::ENVIRONMENTS)) {
            $this->call(DemoSeeder::class);
        } else {
            $this->command?->info('DemoSeeder ignorado: só roda em '.implode('/', DemoSeeder::ENVIRONMENTS).'.');
        }
    }
}
