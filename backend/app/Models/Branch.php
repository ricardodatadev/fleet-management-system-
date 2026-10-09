<?php

namespace App\Models;

use App\Models\Concerns\HasNormalizedCode;
use App\Support\Audit\Auditable;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Unidade/Filial (spec C.2). Sem escopo de filial: lookup visível a quem tem branches.view.
 */
class Branch extends Model
{
    use Auditable, HasNormalizedCode, SoftDeletes;

    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    public const TYPES = ['filial', 'garagem', 'oficina'];

    /** Unidades federativas aceitas em `state`. */
    public const STATES = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    protected $fillable = ['code', 'name', 'type', 'city', 'state', 'is_active'];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Employee, $this> */
    public function employees(): HasMany
    {
        // Sem o escopo de filial: a regra de exclusão precisa enxergar todos.
        return $this->hasMany(Employee::class)->withoutGlobalScopes([Scopes\BranchScope::class]);
    }

    /** @return HasMany<CostCenter, $this> */
    public function costCenters(): HasMany
    {
        // Sem o escopo de filial: a regra de exclusão precisa enxergar todos.
        return $this->hasMany(CostCenter::class)->withoutGlobalScopes([Scopes\BranchScope::class]);
    }

    /**
     * Dependentes ativos (não excluídos) que impedem a exclusão (409). Equipamentos entram na F1-15.
     *
     * @return list<string> chaves de tradução em api.dependents.*
     */
    public function activeDependents(): array
    {
        return array_keys(array_filter([
            'cost_centers' => $this->costCenters()->exists(),
            'users' => $this->users()->exists(),
            'employees' => $this->employees()->exists(),
        ]));
    }
}
