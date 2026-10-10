<?php

namespace App\Policies;

/** Famílias de equipamento: leitura M, L e A; escrita só A (matriz E). Sem escopo de filial. */
class EquipmentFamilyPolicy extends ResourcePolicy
{
    protected function resource(): string
    {
        return 'equipment_families';
    }
}
