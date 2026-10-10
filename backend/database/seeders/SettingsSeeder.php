<?php

namespace Database\Seeders;

use App\Support\Settings\SettingsService;
use Illuminate\Database\Seeder;

/**
 * Valores globais dos parâmetros (F.2): grava o default do registry em cada chave que ainda não tem
 * override global. Nunca sobrescreve um valor já gravado (ex.: alterado pelo admin). Todos os ambientes.
 */
class SettingsSeeder extends Seeder
{
    public function run(SettingsService $settings): void
    {
        $created = 0;
        foreach (SettingsService::registry() as $key => $definition) {
            if ($settings->find($key, 'global', null) === null) {
                $settings->put($key, 'global', null, $definition['default']);
                $created++;
            }
        }
        $this->command?->info("Parâmetros globais: {$created} criado(s), ".(count(SettingsService::registry()) - $created).' já existia(m) (mantidos).');
    }
}
