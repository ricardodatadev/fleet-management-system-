<?php

namespace App\Support\Rbac;

use App\Models\User;

/**
 * Permissões efetivas do usuário (Fase 2A, E.1): as do perfil (config/rbac.php) recortadas pelo escopo
 * de filial. Ponto único usado pelos Gates, pelas policies e pelo `permissions` de GET /auth/me.
 *
 * Quem tem escopo de filial (gestor com filial) não grava em catálogo global, então perde o `*.manage`
 * desses catálogos; admin e gestor sem filial ficam com a lista do perfil.
 */
final class EffectivePermissions
{
    /** `*.manage` de cadastros globais por natureza (models GlobalCatalog). */
    public const GLOBAL_CATALOG_MANAGE = ['equipment_families.manage', 'service_categories.manage'];

    /** @return list<string> */
    public static function for(User $user): array
    {
        $permissions = $user->role->permissions();
        if ($user->seesAllBranches()) {
            return $permissions;
        }

        return array_values(array_diff($permissions, self::GLOBAL_CATALOG_MANAGE));
    }

    /**
     * Pode gravar num registro com este escopo? Sem escopo de filial: sempre. Com escopo: nunca num registro
     * global (catálogo global ou sem filial) e só na própria filial.
     */
    public static function canWriteScope(User $user, ?int $targetBranchId, bool $isGlobalRecord): bool
    {
        if ($user->seesAllBranches()) {
            return true;
        }

        return ! $isGlobalRecord && $targetBranchId !== null && $targetBranchId === (int) $user->branch_id;
    }
}
