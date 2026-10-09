<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use App\Models\Concerns\HasNormalizedCode;
use App\Support\Audit\Auditable;
use Database\Factories\CostCenterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Centro de custo (spec C.3). Não-admin vê os da própria filial + os sem filial.
 */
class CostCenter extends Model
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

    /**
     * Dependentes ativos que impedem a exclusão (409): equipamentos (F1-15) e colaboradores (F1-14).
     *
     * @return list<string>
     */
    public function activeDependents(): array
    {
        return [];
    }
}
