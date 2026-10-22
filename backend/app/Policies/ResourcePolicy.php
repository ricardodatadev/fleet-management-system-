<?php

namespace App\Policies;

use App\Models\Concerns\BranchScoped;
use App\Models\Contracts\GlobalCatalog;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Support\Rbac\EffectivePermissions;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Base das policies de cadastro: leitura → `{recurso}.view`, escrita → `{recurso}.manage`
 * (restore/with_trashed contidos em manage). Por instância aplica também o escopo de filial
 * quando o model usa BranchScoped.
 *
 * Escrita com escopo de filial (Fase 2A, E.1): quem tem escopo (gestor com filial) não grava em registro
 * global nem fora da própria filial, nem no registro atual nem no destino (`branch_id` novo). A negação
 * traz o código `branch_scope` (ou `branch_scope.target` quando a causa é o destino, para o envelope
 * apontar `errors.branch_id`).
 */
abstract class ResourcePolicy
{
    public const DENY_SCOPE = 'branch_scope';

    public const DENY_TARGET = 'branch_scope.target';

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

    /**
     * $draft: registro a criar com o `branch_id` de destino. Sem rascunho não dá para saber o escopo: quem
     * tem escopo de filial é negado (defesa contra um controller que esqueça de passar o destino).
     */
    public function create(User $user, ?Model $draft = null): Response|bool
    {
        if (! $this->manage($user)) {
            return false;
        }
        if ($draft === null) {
            return $user->seesAllBranches() ? Response::allow() : Response::deny(__('api.branch_write_denied'), self::DENY_SCOPE);
        }

        return $this->scope($user, $draft, true);
    }

    /** @param  array<string, mixed>  $changes  dados validados da requisição (só o `branch_id` importa) */
    public function update(User $user, Model $model, array $changes = []): Response|bool
    {
        if (! $this->manage($user) || ! BranchScope::allows($user, $model)) {
            return false;
        }
        $current = $this->scope($user, $model, false);
        if ($current->denied() || ! array_key_exists('branch_id', $changes)) {
            return $current;
        }

        return $this->scope($user, (clone $model)->forceFill(['branch_id' => $changes['branch_id']]), true);
    }

    public function delete(User $user, Model $model): Response|bool
    {
        return $this->update($user, $model);
    }

    public function restore(User $user, Model $model): Response|bool
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

    /** Regra única de escrita por escopo (E.1); também usada pelos settings. */
    public static function canWriteScope(User $user, ?int $targetBranchId, bool $isGlobalRecord): bool
    {
        return EffectivePermissions::canWriteScope($user, $targetBranchId, $isGlobalRecord);
    }

    protected function manage(User $user): bool
    {
        return $user->hasPermission($this->resource().'.manage');
    }

    /**
     * Escopo do registro: catálogo global por natureza (GlobalCatalog), sem filial num model BranchScoped que
     * aceita nulo (centro de custo global) ou da filial do `branch_id`. Model sem escopo nenhum: livre.
     */
    private function scope(User $user, Model $model, bool $isTarget): Response
    {
        $catalog = $model instanceof GlobalCatalog;
        if (! $catalog && ! in_array(BranchScoped::class, class_uses_recursive($model), true)) {
            return Response::allow();
        }
        $branchId = $catalog ? null : $model->getAttribute('branch_id');
        $global = $catalog || ($branchId === null && method_exists($model, 'branchScopeIncludesNull') && $model->branchScopeIncludesNull());

        if (self::canWriteScope($user, $branchId === null ? null : (int) $branchId, $global)) {
            return Response::allow();
        }

        return Response::deny(__('api.branch_write_denied'), $isTarget && ! $catalog ? self::DENY_TARGET : self::DENY_SCOPE);
    }
}
