<?php

namespace App\Support\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Convenções de lista da API (spec D.1/D.2): paginação (`page`, `per_page` ≤ 100, ou ≤ 200 com
 * `is_active=1`), busca `q` (ILIKE com % e _ escapados), `sort` multi-campo por whitelist (`-` = desc),
 * filtros planos declarados e `with_trashed=1` (exige a ability `viewTrashed` da policy → senão 403).
 *
 *   ListQuery::for($request, Branch::query())
 *       ->search(['code', 'name'])
 *       ->filters(['type' => ['string', Rule::in(...)], 'is_active' => ['boolean']])
 *       ->sortable(['code', 'name', 'created_at'], 'code')
 *       ->paginate();
 *
 * Parâmetros fora dos declarados são ignorados; valores inválidos → 422. `fixedOrder()` troca o `sort`
 * por uma ordenação fixa (o parâmetro passa a ser ignorado). `with_trashed` só existe em models com SoftDeletes.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
class ListQuery
{
    public const PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /** Limite para selects (somente com is_active=1). */
    public const MAX_PER_PAGE_ACTIVE = 200;

    /** @var list<string> */
    private array $searchColumns = [];

    /** @var array<string, array<int, mixed>> */
    private array $filterRules = [];

    /** @var array<string, \Closure(Builder<TModel>, mixed): void> */
    private array $filterHandlers = [];

    /** @var list<string> */
    private array $sortColumns = [];

    private string $defaultSort = 'id';

    /** @var list<array{0: string, 1: 'asc'|'desc'}>|null */
    private ?array $fixedOrder = null;

    /** @param  Builder<TModel>  $query */
    private function __construct(private readonly Request $request, private readonly Builder $query) {}

    /**
     * @template TFor of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TFor>  $query
     * @return self<TFor>
     */
    public static function for(Request $request, Builder $query): self
    {
        return new self($request, $query);
    }

    /** @param  list<string>  $columns */
    public function search(array $columns): self
    {
        $this->searchColumns = $columns;

        return $this;
    }

    /**
     * Filtros planos: nome => regras de validação. Por padrão filtra `coluna = valor`; um handler
     * próprio pode ser passado em $handlers.
     *
     * @param  array<string, array<int, mixed>>  $rules
     * @param  array<string, \Closure(Builder<TModel>, mixed): void>  $handlers
     */
    public function filters(array $rules, array $handlers = []): self
    {
        $this->filterRules = $rules;
        $this->filterHandlers = $handlers;

        return $this;
    }

    /** @param  list<string>  $columns */
    public function sortable(array $columns, string $default): self
    {
        $this->sortColumns = $columns;
        $this->defaultSort = $default;

        return $this;
    }

    /**
     * Ordenação fixa, sem `sort` do cliente e sem o desempate por id asc (inclua o id em $orders).
     *
     * @param  list<array{0: string, 1: 'asc'|'desc'}>  $orders
     */
    public function fixedOrder(array $orders): self
    {
        $this->fixedOrder = $orders;

        return $this;
    }

    /** @return LengthAwarePaginator<int, TModel> */
    public function paginate(): LengthAwarePaginator
    {
        $input = $this->validated();
        $query = $this->query;
        $model = $query->getModel();

        if (! empty($input['with_trashed'])) {
            Gate::authorize('viewTrashed', $model::class);
            $query->withoutGlobalScope(SoftDeletingScope::class); // = withTrashed() do SoftDeletes
        }

        foreach ($this->filterRules as $name => $rules) {
            if (! array_key_exists($name, $input) || $input[$name] === null) {
                continue;
            }
            $value = in_array('boolean', $rules, true) ? filter_var($input[$name], FILTER_VALIDATE_BOOLEAN) : $input[$name];
            isset($this->filterHandlers[$name])
                ? ($this->filterHandlers[$name])($query, $value)
                : $query->where($model->qualifyColumn($name), $value);
        }

        $term = trim((string) ($input['q'] ?? ''));
        if ($term !== '' && $this->searchColumns !== []) {
            $like = '%'.addcslashes($term, '\\%_').'%';
            $query->where(function (Builder $q) use ($like, $model) {
                foreach ($this->searchColumns as $column) {
                    $q->orWhere($model->qualifyColumn($column), 'ilike', $like);
                }
            });
        }

        if ($this->fixedOrder !== null) {
            foreach ($this->fixedOrder as [$column, $direction]) {
                $query->orderBy($model->qualifyColumn($column), $direction);
            }
        } else {
            foreach ($this->sorts($input['sort'] ?? null) as [$column, $direction]) {
                $query->orderBy($model->qualifyColumn($column), $direction);
            }
            $query->orderBy($model->qualifyColumn($model->getKeyName())); // desempate estável
        }

        return $query->paginate((int) ($input['per_page'] ?? self::PER_PAGE), ['*'], 'page', (int) ($input['page'] ?? 1));
    }

    /** @return array<string, mixed> */
    private function validated(): array
    {
        $activeOnly = $this->request->has('is_active') && filter_var($this->request->query('is_active'), FILTER_VALIDATE_BOOLEAN);
        $field = '-?('.implode('|', array_map(fn ($c) => preg_quote($c, '/'), $this->sortColumns ?: [$this->defaultSort])).')';
        $sortPattern = "/^{$field}(,{$field})*$/";

        $rules = [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.($activeOnly ? self::MAX_PER_PAGE_ACTIVE : self::MAX_PER_PAGE)],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
        if ($this->fixedOrder === null) {
            $rules['sort'] = ['sometimes', 'string', 'regex:'.$sortPattern];
        }
        if (in_array(SoftDeletes::class, class_uses_recursive($this->query->getModel()), true)) {
            $rules['with_trashed'] = ['sometimes', 'boolean'];
        }
        foreach ($this->filterRules as $name => $filterRules) {
            $rules[$name] = ['sometimes', 'nullable', ...$filterRules];
        }

        return Validator::make($this->request->query(), $rules)->validate();
    }

    /** @return list<array{0: string, 1: 'asc'|'desc'}> */
    private function sorts(?string $sort): array
    {
        $sorts = [];
        foreach (explode(',', $sort ?? $this->defaultSort) as $part) {
            $sorts[] = str_starts_with($part, '-') ? [substr($part, 1), 'desc'] : [$part, 'asc'];
        }

        return $sorts;
    }
}
