<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use App\Models\Concerns\HasNormalizedCode;
use App\Models\Contracts\CrudModel;
use App\Support\Audit\Auditable;
use Database\Factories\CostCenterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Centro de custo (spec C.3). Não-admin vê os da própria filial + os sem filial.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int|null $branch_id
 * @property bool $is_active
 * @property-read Branch|null $branch
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class CostCenter extends Model implements CrudModel
{
    use Auditable, BranchScoped, HasNormalizedCode, SoftDeletes;

    /** @use HasFactory<CostCenterFactory> */
    use HasFactory;

    protected bool $branchScopeIncludesNull = true;

    protected $fillable = ['code', 'name', 'branch_id', 'is_active'];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    /** @return HasMany<Employee, $this> */
    public function employees(): HasMany
    {
        // Sem o escopo de filial: as regras de exclusão e de troca de filial precisam enxergar todos.
        return $this->hasMany(Employee::class)->withoutGlobalScopes([Scopes\BranchScope::class]);
    }

    /**
     * Dependentes ativos que impedem a exclusão (409): colaboradores e equipamentos.
     *
     * @return list<string>
     */
    public function activeDependents(): array
    {
        return array_keys(array_filter([
            'employees' => $this->employees()->exists(),
            'equipments' => Equipment::query()->withoutGlobalScope(Scopes\BranchScope::class)->where('cost_center_id', $this->getKey())->exists(),
        ]));
    }
}
