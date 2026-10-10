<?php

namespace App\Policies;

use App\Models\Scopes\BranchScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Base das policies de cadastro: leitura → `{recurso}.view`, escrita → `{recurso}.manage`
 * (restore/with_trashed contidos em manage). Por instância aplica também o escopo de filial
 * quando o model usa BranchScoped.
 */
abstract class ResourcePolicy
{
    /** Prefixo das permissões em config/rbac.php (ex.: 'branches'). */
    abstract protected function resource(): string;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission($this->resource().'.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user) && BranchScope::allows($user, $model);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->manage($user) && BranchScope::allows($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }

    public function restore(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    /** `?with_trashed=1` (spec D.1): mesma regra do manage. */
    public function viewTrashed(User $user): bool
    {
        return $this->manage($user);
    }

    protected function manage(User $user): bool
    {
        return $user->hasPermission($this->resource().'.manage');
    }
}
