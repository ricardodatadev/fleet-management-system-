<?php

namespace App\Policies;

/** Usuários (admin). Regras de auto-exclusão/desativação ficam no CRUD (F1-11). */
class UserPolicy extends ResourcePolicy
{
    protected function resource(): string
    {
        return 'users';
    }
}
