<?php

namespace App\Observers;

use App\Enums\AuditAction;
use App\Support\Audit\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/** Observer genérico usado pelo trait Auditable. */
class AuditObserver
{
    public function __construct(private readonly AuditService $audit) {}

    public function created(Model $model): void
    {
        if (! $this->enabled($model, AuditAction::Created)) {
            return;
        }
        $this->audit->record(AuditAction::Created, $model, null, $this->snapshot($model));
    }

    public function updated(Model $model): void
    {
        if (! $this->enabled($model, AuditAction::Updated)) {
            return;
        }
        $changes = Arr::except($model->getChanges(), $this->exclude($model));
        // Só deleted_at mudou = soft delete/restore, registrado por deleted/restored.
        unset($changes['deleted_at']);
        if ($changes === []) {
            return;
        }
        $old = array_intersect_key($model->getRawOriginal(), $changes);
        $this->audit->record(AuditAction::Updated, $model, $old, $changes);
    }

    public function deleted(Model $model): void
    {
        if (! $this->enabled($model, AuditAction::Deleted)) {
            return;
        }
        $soft = method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting();
        $this->audit->record(AuditAction::Deleted, $model, $this->snapshot($model), null, ['soft_delete' => $soft]);
    }

    public function restored(Model $model): void
    {
        if (! $this->enabled($model, AuditAction::Restored)) {
            return;
        }
        $this->audit->record(AuditAction::Restored, $model, null, $this->snapshot($model));
    }

    private function enabled(Model $model, AuditAction $action): bool
    {
        $events = property_exists($model, 'auditEvents') ? $model->auditEvents : null;

        return $events === null || in_array($action->value, $events, true);
    }

    /** Campos sensíveis (password, remember_token) são sempre excluídos, mesmo que o model sobrescreva. */
    private function exclude(Model $model): array
    {
        $own = property_exists($model, 'auditExclude') ? $model->auditExclude : ['password', 'remember_token', 'updated_at'];

        return array_values(array_unique([...$own, 'password', 'remember_token']));
    }

    private function snapshot(Model $model): array
    {
        return Arr::except($model->getAttributes(), $this->exclude($model));
    }
}
