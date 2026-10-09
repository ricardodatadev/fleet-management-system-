<?php

namespace App\Models\Scopes;

use App\Models\Concerns\BranchScoped;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Escopo de filial (spec D.2 "Escopo de filial"): usuário autenticado não-admin só enxerga registros
 * da própria filial. Admin (com ou sem filial) e contextos sem usuário (console, fila) não são filtrados.
 * Regras de validação `exists` usam o query builder e não passam por aqui.
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();
        if (! $user instanceof User || $user->isAdmin()) {
            return;
        }

        $column = $model->qualifyColumn('branch_id');
        $includesNull = method_exists($model, 'branchScopeIncludesNull') && $model->branchScopeIncludesNull();

        $builder->where(function (Builder $query) use ($column, $user, $includesNull) {
            $query->where($column, $user->branch_id);
            if ($includesNull) {
                $query->orWhereNull($column);
            }
        });
    }

    /** Mesmo critério para um registro já carregado (usado pelas policies). Model sem BranchScoped: sem escopo. */
    public static function allows(User $user, Model $model): bool
    {
        if ($user->isAdmin() || ! in_array(BranchScoped::class, class_uses_recursive($model), true)) {
            return true;
        }
        $branchId = $model->getAttribute('branch_id');
        $includesNull = $model->branchScopeIncludesNull();

        return ($branchId === null && $includesNull) || ($branchId !== null && (int) $branchId === (int) $user->branch_id);
    }
}
