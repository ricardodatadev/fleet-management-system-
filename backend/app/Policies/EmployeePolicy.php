<?php

namespace App\Policies;

/** Colaboradores: leitura L (própria filial, BranchScoped) e A; escrita só A (matriz E). */
class EmployeePolicy extends ResourcePolicy
{
    protected function resource(): string
    {
        return 'employees';
    }
}
