<?php

namespace App\Enums;

/** Perfis de acesso (spec E; `manager` = Gestor/PCM, Fase 2A D18). Um perfil por usuário; extensível (D13). */
enum Role: string
{
    case Operator = 'operator';
    case Mechanic = 'mechanic';
    case Leader = 'leader';
    case Manager = 'manager';
    case Admin = 'admin';

    /** Valores na ordem da matriz (para atributos OpenAPI, que não aceitam chamada de método). */
    public const VALUES = ['operator', 'mechanic', 'leader', 'manager', 'admin'];

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

    /** Perfis que podem ficar sem filial (nulo = todas as filiais; D18). */
    public function allowsNullBranch(): bool
    {
        return $this === self::Admin || $this === self::Manager;
    }
}
