<?php

use App\Http\Controllers\Concerns\CrudActions;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\EquipmentFamily;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Expõe a base de CRUD (as ações são protected). */
function crudBase(): object
{
    return new class
    {
        use CrudActions;

        public function delete(Model $model): void
        {
            $this->softDeleteGuarded($model);
        }
    };
}

it('softDeleteGuarded: linha travada já excluída (exclusão concorrente) → 404, sem excluir de novo nem novo audit deleted', function (string $class) {
    /** @var Model $stale */
    $stale = $class::factory()->create(); // instância carregada pela requisição que chegou depois (binding já feito)
    $stale::query()->findOrFail($stale->getKey())->delete(); // a outra requisição excluiu antes
    $deletedAt = $stale::withTrashed()->findOrFail($stale->getKey())->deleted_at;

    expect(fn () => crudBase()->delete($stale))->toThrow(ModelNotFoundException::class);

    $logs = AuditLog::query()->where('auditable_type', $stale->getMorphClass())->where('auditable_id', $stale->getKey())->where('action', 'deleted')->count();
    expect($logs)->toBe(1)
        ->and($stale::withTrashed()->findOrFail($stale->getKey())->deleted_at->equalTo($deletedAt))->toBeTrue();
})->with([
    'filial' => [Branch::class],
    'centro de custo' => [CostCenter::class],
    'família' => [EquipmentFamily::class],
    'usuário' => [User::class],
]);

it('softDeleteGuarded: registro ativo é excluído uma vez (caminho normal)', function () {
    $family = EquipmentFamily::factory()->create();

    crudBase()->delete($family);

    expect(EquipmentFamily::withTrashed()->find($family->id)->trashed())->toBeTrue();
    expect(AuditLog::query()->where('auditable_id', $family->id)->where('auditable_type', $family->getMorphClass())->where('action', 'deleted')->count())->toBe(1);
});
