<?php

namespace App\Policies;

/** Centros de custo: escopo de filial com os sem filial (BranchScoped + branchScopeIncludesNull). */
class CostCenterPolicy extends ResourcePolicy
{
    protected function resource(): string
    {
        return 'cost_centers';
    }
}
