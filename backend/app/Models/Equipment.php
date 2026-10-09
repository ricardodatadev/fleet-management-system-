<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use App\Models\Concerns\HasNormalizedCode;
use App\Support\Audit\Auditable;
use Database\Factories\EquipmentFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Equipamento/frota (spec C.6). Escopo de filial: não-admin vê só a própria filial. Criticidade efetiva
 * = override ?? família. Odômetro e horímetro são valores manuais (sem telemetria na Fase 1).
 */
class Equipment extends Model
{
    use Auditable, BranchScoped, HasNormalizedCode, SoftDeletes;

    /** @use HasFactory<EquipmentFactory> */
    use HasFactory;

    protected $table = 'equipments';

    public const STATUSES = ['active', 'inactive', 'disposed'];

    public const MIN_YEAR = 1950;

    protected $fillable = [
        'code', 'name', 'family_id', 'branch_id', 'cost_center_id', 'responsible_employee_id', 'plate',
        'serial_number', 'manufacturer', 'model', 'year', 'status', 'criticality_override',
        'odometer_km', 'hour_meter', 'acquisition_date', 'acquisition_value', 'notes',
    ];

    /** Padrões da C.6 (iguais aos DEFAULT do banco), para a resposta do create já sair completa. */
    protected $attributes = [
        'status' => 'active',
        'odometer_km' => 0,
        'hour_meter' => 0,
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            // numeric chega como string do PDO: a API devolve número JSON
            'odometer_km' => 'float',
            'hour_meter' => 'float',
            'acquisition_value' => 'float',
            'acquisition_date' => 'date:Y-m-d',
        ];
    }

    /** Ano máximo aceito: o atual + 1 (modelo do ano seguinte). */
    public static function maxYear(): int
    {
        return (int) now()->year + 1;
    }

    /** Formato da placa depois de normalizada (v1.12): sem restringir ao padrão brasileiro. */
    public const PLATE_REGEX = '/^[A-Z0-9]{5,8}$/';

    /** Placa em maiúsculas, sem espaços nem hífen (a unicidade é sobre esse valor). */
    public static function normalizePlate(?string $plate): ?string
    {
        return $plate === null ? null : mb_strtoupper((string) preg_replace('/[\s-]+/u', '', $plate));
    }

    protected function plate(): Attribute
    {
        return Attribute::set(fn (?string $value) => static::normalizePlate($value));
    }

    /** Criticidade efetiva: override do equipamento ou a da família. */
    public function effectiveCriticality(): ?string
    {
        return $this->criticality_override ?? $this->family?->criticality;
    }

    public function criticalitySource(): string
    {
        return $this->criticality_override !== null ? 'override' : 'family';
    }

    /** @return BelongsTo<EquipmentFamily, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(EquipmentFamily::class)->withTrashed();
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

    /** @return BelongsTo<Employee, $this> */
    public function responsibleEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'responsible_employee_id')->withTrashed()->withoutGlobalScope(Scopes\BranchScope::class);
    }

    /**
     * Dependentes ativos que impedem a exclusão (409). Nenhum na Fase 1 (OS, preventiva etc. vêm depois).
     *
     * @return list<string>
     */
    public function activeDependents(): array
    {
        return [];
    }
}
