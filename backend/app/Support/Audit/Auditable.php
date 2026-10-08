<?php

namespace App\Support\Audit;

use App\Observers\AuditObserver;

/**
 * Audita created/updated/deleted/restored do model.
 *
 * Propriedades opcionais no model:
 *  - public array $auditExclude (default: password, remember_token, updated_at; password/remember_token são sempre excluídos)
 *  - public array $auditEvents  (default: created, updated, deleted, restored)
 *    Atenção: se $auditExclude for definido sem 'updated_at', restore() gera também um 'updated'
 *    (diff de updated_at) além do 'restored'.
 *
 * Convenção: execute a mutação dentro de DB::transaction() para que o log seja atômico com a mudança.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        // Não usa static::observe(): ele instancia o model durante o boot (LogicException no Laravel 13).
        foreach (['created', 'updated', 'deleted', 'restored'] as $event) {
            static::registerModelEvent($event, fn ($model) => app(AuditObserver::class)->{$event}($model));
        }
    }
}
