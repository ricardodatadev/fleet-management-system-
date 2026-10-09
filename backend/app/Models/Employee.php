<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use App\Support\Audit\Auditable;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Colaborador (spec C.5). Escopo de filial: não-admin vê só a própria filial. Vínculo 1:1 opcional
 * com User. Campos de CNH só para motorista; especialidade e custo/hora só para mecânico.
 */
class Employee extends Model
{
    use Auditable, BranchScoped, SoftDeletes;

    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    public const JOB_TYPES = ['driver', 'mechanic', 'leader', 'admin_staff'];

    public const CNH_CATEGORIES = ['A', 'B', 'C', 'D', 'E', 'AB', 'AC', 'AD', 'AE'];

    /** Campos que só valem para um job_type (os demais tipos não têm campos próprios). */
    public const TYPE_FIELDS = [
        'driver' => ['cnh_number', 'cnh_category', 'cnh_expires_at'],
        'mechanic' => ['specialty', 'hourly_cost'],
    ];

    protected $fillable = [
        'user_id', 'registration', 'name', 'job_type', 'branch_id', 'cost_center_id', 'phone', 'hired_at',
        'cnh_number', 'cnh_category', 'cnh_expires_at', 'specialty', 'hourly_cost', 'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'hired_at' => 'date:Y-m-d',
            'cnh_expires_at' => 'date:Y-m-d',
            // numeric(10,2) chega como string do PDO: a API devolve número JSON (contrato v1.5)
            'hourly_cost' => 'float',
            'is_active' => 'boolean',
        ];
    }

    /** Matrícula sem espaços nas pontas e em maiúsculas (como `code` nos demais cadastros). */
    protected function registration(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null ? null : mb_strtoupper(trim($value)));
    }

    /** Todos os campos específicos de algum job_type. */
    public static function typeFields(): array
    {
        return array_merge(...array_values(self::TYPE_FIELDS));
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class)->withTrashed()->withoutGlobalScope(Scopes\BranchScope::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Dependentes ativos que impedem a exclusão (409): equipamentos sob a responsabilidade do colaborador.
     *
     * @return list<string>
     */
    public function activeDependents(): array
    {
        return array_keys(array_filter([
            'equipments' => Equipment::query()->withoutGlobalScope(Scopes\BranchScope::class)->where('responsible_employee_id', $this->getKey())->exists(),
        ]));
    }
}
