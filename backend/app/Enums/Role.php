<?php

namespace App\Enums;

/** Perfis de acesso (spec E). Um perfil por usuário; extensível (D13). */
enum Role: string
{
    case Operator = 'operator';
    case Mechanic = 'mechanic';
    case Leader = 'leader';
    case Admin = 'admin';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Permissões do perfil conforme config/rbac.php (auth.* é implícito e não aparece). */
    public function permissions(): array
    {
        return config("rbac.roles.{$this->value}", []);
    }
}
