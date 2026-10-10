<?php

namespace App\Models\Concerns;

use App\Models\Scopes\BranchScope;

/**
 * Aplica o escopo de filial ao model (coluna branch_id).
 *
 * Opcional no model: `protected bool $branchScopeIncludesNull = true;` para o não-admin enxergar também
 * os registros sem filial (ex.: centros de custo globais, F1-12).
 */
trait BranchScoped
{
    public static function bootBranchScoped(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    public function branchScopeIncludesNull(): bool
    {
        return property_exists($this, 'branchScopeIncludesNull') && $this->branchScopeIncludesNull;
    }
}
