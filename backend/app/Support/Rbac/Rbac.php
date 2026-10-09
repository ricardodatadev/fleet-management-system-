<?php

namespace App\Support\Rbac;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Registro dos Gates de permissão (spec E). Fonte: config/rbac.php.
 *
 * Um Gate por permissão (`recurso.acao`), concedido se a permissão está na lista do perfil do usuário,
 * mais o Gate implícito `auth.session` (qualquer usuário autenticado; representa o `auth.*` da matriz).
 */
class Rbac
{
    public const SESSION = 'auth.session';

    /** @return list<string> Todas as permissões declaradas em config/rbac.php (sem repetição). */
    public static function permissions(): array
    {
        return array_values(array_unique(array_merge(...array_values(config('rbac.roles', [])))));
    }

    /** Abilities válidas para `can:` em rotas: as permissões + `auth.session`. */
    public static function abilities(): array
    {
        return [self::SESSION, ...self::permissions()];
    }

    public static function register(): void
    {
        Gate::define(self::SESSION, fn (User $user) => true);

        foreach (self::permissions() as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
    }
}
