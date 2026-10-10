<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authorize;
use LogicException;

/**
 * Alias `can:` das rotas. Roda ANTES do SubstituteBindings (prioridade em bootstrap/app.php): sem a
 * permissão do recurso a resposta é 403 mesmo para um {id} de outra filial, que o binding (com o escopo
 * de filial) transformaria em 404. Com a permissão e o registro fora do escopo → 404.
 *
 * Por isso só aceita abilities sem argumento ou com nome de classe (`can:create,App\Models\Branch`).
 * Abilities por instância (view/update de um registro) ficam no controller, depois do binding.
 */
class AuthorizePermission extends Authorize
{
    protected function getModel($request, $model)
    {
        if (! $this->isClassName($model)) {
            throw new LogicException("can:{$model}: parâmetro de rota não é aceito no middleware can (roda antes do binding); autorize a instância no controller.");
        }

        return parent::getModel($request, $model);
    }
}
