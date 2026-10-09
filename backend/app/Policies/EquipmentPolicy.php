<?php

namespace App\Policies;

/** Equipamentos: leitura de todos os perfis (não-admin na própria filial, BranchScoped); escrita só A. */
class EquipmentPolicy extends ResourcePolicy
{
    protected function resource(): string
    {
        return 'equipments';
    }
}
