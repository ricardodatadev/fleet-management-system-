<?php

namespace App\Models\Contracts;

/**
 * Model de cadastro usado pela base de CRUD (CrudActions): soft delete com a regra de dependentes ativos
 * e restore. `restore()` vem do trait SoftDeletes.
 */
interface CrudModel
{
    /** @return list<string> chaves de tradução em api.dependents.* */
    public function activeDependents(): array;

    /**
     * Sem tipo de retorno na assinatura: o SoftDeletes::restore() do framework não declara um.
     *
     * @return bool
     */
    public function restore();
}
