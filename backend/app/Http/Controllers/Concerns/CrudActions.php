<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\DomainConflictException;
use App\Models\Branch;
use App\Support\Api\FieldMessage;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ações comuns dos cadastros (convenções de CRUD da D.2). Toda mutação roda em DB::transaction
 * (o audit trail grava na mesma transação).
 */
trait CrudActions
{
    /**
     * Grava (create/update). Se um índice único parcial for violado por concorrência após a validação,
     * responde 422 no campo, como a própria validação faria. $uniqueField é o campo, ou um mapa
     * nome do índice → campo quando a tabela tem mais de um (o primeiro é o padrão).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  string|array<string, string>  $uniqueField
     * @return T
     */
    protected function persist(Closure $callback, string|array $uniqueField = 'code'): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (UniqueConstraintViolationException $e) {
            $fields = (array) $uniqueField;
            $field = reset($fields);
            foreach ($fields as $index => $candidate) {
                if (is_string($index) && str_contains($e->getMessage(), $index)) {
                    $field = $candidate;
                }
            }

            throw ValidationException::withMessages([$field => FieldMessage::for('unique', $field)]);
        }
    }

    /**
     * Soft delete com a regra de dependentes ativos (409). A linha é travada (FOR UPDATE) antes da
     * checagem: quem cria um filho trava a mesma linha (FOR SHARE) e, por isso, espera esta transação
     * e então vê o pai excluído. Se a linha travada já estiver excluída (exclusão concorrente que
     * chegou antes), responde 404 sem excluir de novo nem gerar outro evento `deleted`.
     *
     * $before roda na mesma transação ANTES de travar a linha (ex.: travar outras linhas numa ordem fixa
     * e lançar 409); $after roda depois da exclusão (ex.: revogar tokens).
     *
     * @param  (Closure(): void)|null  $before
     * @param  (Closure(Model): void)|null  $after
     */
    protected function softDeleteGuarded(Model $model, ?Closure $before = null, ?Closure $after = null): void
    {
        DB::transaction(function () use ($model, $before, $after) {
            if ($before !== null) {
                $before();
            }
            $locked = $model->newQueryWithoutScopes()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->getAttribute('deleted_at') !== null) {
                throw (new ModelNotFoundException)->setModel($model::class, [$model->getKey()]);
            }

            $dependents = $locked->activeDependents();
            if ($dependents !== []) {
                $labels = array_map(fn (string $key) => __("api.dependents.{$key}"), $dependents);

                throw new DomainConflictException(
                    __('api.delete_has_dependents', ['list' => implode(', ', $labels)]),
                    ['dependents' => $dependents],
                );
            }

            $locked->delete();
            if ($after !== null) {
                $after($locked);
            }
        });
    }

    /**
     * Restaura um registro excluído. 409 se não estiver excluído, se o valor único ($uniqueField: `code`,
     * ou `email` em usuários) já foi reutilizado por um registro ativo, ou se $check (regra do recurso)
     * devolver uma mensagem de conflito.
     *
     * @param  (Closure(Model): ?string)|null  $check
     */
    protected function restoreGuarded(Model $model, ?Closure $check = null, string $uniqueField = 'code'): Model
    {
        $taken = fn () => new DomainConflictException(__("api.restore_{$uniqueField}_taken"), [$uniqueField => [__("api.restore_{$uniqueField}_taken")]]);

        try {
            return DB::transaction(function () use ($model, $check, $uniqueField, $taken) {
                $locked = $model->newQueryWithoutScopes()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->getAttribute('deleted_at') === null) {
                    throw new DomainConflictException(__('api.restore_not_deleted'));
                }
                $value = $locked->getAttribute($uniqueField);
                if ($value !== null && $model->newQueryWithoutScopes()->whereNull('deleted_at')->where($uniqueField, $value)->exists()) {
                    throw $taken();
                }
                if ($check !== null && ($message = $check($locked)) !== null) {
                    throw new DomainConflictException($message);
                }

                $locked->restore();

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw $taken();
        }
    }

    /**
     * Trava a filial (FOR SHARE) e confirma que segue não excluída (senão 422 em branch_id): a exclusão
     * da filial trava a mesma linha (FOR UPDATE), então as duas operações não se cruzam. Chamar dentro
     * da transação da gravação.
     */
    protected function lockBranch(mixed $branchId): void
    {
        if ($branchId !== null && Branch::query()->whereKey($branchId)->sharedLock()->first(['id']) === null) {
            throw ValidationException::withMessages(['branch_id' => FieldMessage::for('exists', 'branch_id')]);
        }
    }
}
