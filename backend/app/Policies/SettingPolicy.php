<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Escrita de overrides por escopo (Fase 2A, seção E e ajuste 7): quem tem escopo de filial (gestor com
 * filial) só grava e remove overrides `branch` da própria filial; `global` e `family` valem para todas as
 * filiais e ficam com o admin e o gestor sem filial. A permissão `settings.manage` é checada na rota.
 */
class SettingPolicy
{
    public function manageScope(User $user, string $scopeType, mixed $scopeId): Response
    {
        $branchId = $scopeType === 'branch' && is_numeric($scopeId) ? (int) $scopeId : null;

        return ResourcePolicy::canWriteScope($user, $branchId, $scopeType !== 'branch')
            ? Response::allow()
            : Response::deny(__('api.branch_write_denied'), ResourcePolicy::DENY_SCOPE);
    }
}
