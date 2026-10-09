<?php

namespace App\Models;

use App\Models\Concerns\HasNormalizedCode;
use App\Support\Audit\Auditable;
use Database\Factories\EquipmentFamilyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Família/Classe de equipamento (spec C.4). Sem escopo de filial (cadastro global). Os campos da
 * RN-001 (preventive_lead_pct, tolerance_*) são só persistidos; não há motor de regra na Fase 1.
 */
class EquipmentFamily extends Model
{
    use Auditable, HasNormalizedCode, SoftDeletes;

    /** @use HasFactory<EquipmentFamilyFactory> */
    use HasFactory;

    public const CATEGORIES = ['light_vehicle', 'truck', 'agri_machine', 'implement', 'support'];

    public const CRITICALITIES = ['low', 'medium', 'high', 'critical'];

    protected $fillable = [
        'code', 'name', 'category', 'criticality', 'preventive_lead_pct',
        'tolerance_km', 'tolerance_hours', 'tolerance_days', 'is_active',
    ];

    /** Padrões da C.4 (iguais aos DEFAULT do banco), para a resposta do create já sair completa. */
    protected $attributes = [
        'criticality' => 'medium',
        'preventive_lead_pct' => 90,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            // numeric(5,2) chega como string do PDO: a API devolve número JSON (contrato v1.4)
            'preventive_lead_pct' => 'float',
            'tolerance_km' => 'integer',
            'tolerance_hours' => 'integer',
            'tolerance_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Equipment, $this> */
    public function equipments(): HasMany
    {
        // Sem o escopo de filial: a regra de exclusão precisa enxergar todos.
        return $this->hasMany(Equipment::class, 'family_id')->withoutGlobalScopes([Scopes\BranchScope::class]);
    }

    /**
     * Dependentes ativos que impedem a exclusão (409): equipamentos.
     *
     * @return list<string> chaves de tradução em api.dependents.*
     */
    public function activeDependents(): array
    {
        return array_keys(array_filter([
            'equipments' => $this->equipments()->exists(),
        ]));
    }
}
