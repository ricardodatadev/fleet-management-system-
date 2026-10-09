<?php

namespace App\Policies;

/** Filiais: lookup sem escopo de filial (visível a quem tem branches.view). CRUD na F1-12. */
class BranchPolicy extends ResourcePolicy
{
    protected function resource(): string
    {
        return 'branches';
    }
}
