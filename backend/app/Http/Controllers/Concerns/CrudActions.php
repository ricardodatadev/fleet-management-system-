<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\DomainConflictException;
use Closure;
use Illuminate\Database\Eloquent\Model;
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
     * Grava (create/update). Se o índice único parcial de `code` for violado por concorrência após a
     * validação, responde 422 no campo, como a própria validação faria.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function persist(Closure $callback, string $uniqueField = 'code'): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([$uniqueField => __('validation.unique', ['attribute' => $uniqueField])]);
        }
    }

    /**
     * Soft delete com a regra de dependentes ativos (409). A linha é travada (FOR UPDATE) antes da
     * checagem: quem cria um filho trava a mesma linha (FOR SHARE) e, por isso, espera esta transação
     * e então vê o pai excluído.
     */
    protected function softDeleteGuarded(Model $model): void
    {
        DB::transaction(function () use ($model) {
            $locked = $model->newQueryWithoutScopes()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();

            $dependents = $locked->activeDependents();
            if ($dependents !== []) {
                $labels = array_map(fn (string $key) => __("api.dependents.{$key}"), $dependents);

                throw new DomainConflictException(
                    __('api.delete_has_dependents', ['list' => implode(', ', $labels)]),
                    ['dependents' => $dependents],
                );
            }

            $locked->delete();
        });
    }

    /**
     * Restaura um registro excluído. 409 se não estiver excluído, se o código já foi reutilizado por um
     * registro ativo, ou se $check (regra do recurso) devolver uma mensagem de conflito.
     *
     * @param  (Closure(Model): ?string)|null  $check
     */
    protected function restoreGuarded(Model $model, ?Closure $check = null): Model
    {
        try {
            return DB::transaction(function () use ($model, $check) {
                $locked = $model->newQueryWithoutScopes()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->getAttribute('deleted_at') === null) {
                    throw new DomainConflictException(__('api.restore_not_deleted'));
                }
                $code = $locked->getAttribute('code');
                $codeTaken = $code !== null && $model->newQueryWithoutScopes()->whereNull('deleted_at')->where('code', $code)->exists();
                if ($codeTaken) {
                    throw new DomainConflictException(__('api.restore_code_taken'), ['code' => [__('api.restore_code_taken')]]);
                }
                if ($check !== null && ($message = $check($locked)) !== null) {
                    throw new DomainConflictException($message);
                }

                $locked->restore();

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainConflictException(__('api.restore_code_taken'), ['code' => [__('api.restore_code_taken')]]);
        }
    }
}
