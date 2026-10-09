<?php

namespace App\Policies;

/** Filiais: lookup sem escopo de filial (visível a quem tem branches.view). */
class BranchPolicy extends ResourcePolicy
{
    protected function resource(): string
    {
        return 'branches';
    }
}
