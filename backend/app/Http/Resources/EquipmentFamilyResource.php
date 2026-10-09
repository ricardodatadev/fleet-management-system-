<?php

namespace App\Http\Resources;

use App\Models\EquipmentFamily;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * @mixin EquipmentFamily
 */
#[OA\Schema(
    schema: 'EquipmentFamily',
    required: ['id', 'code', 'name', 'category', 'criticality', 'preventive_lead_pct', 'tolerance_km', 'tolerance_hours', 'tolerance_days', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'code', type: 'string', maxLength: 30, example: 'CAM-PESADO'),
        new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'Caminhões pesados'),
        new OA\Property(property: 'category', type: 'string', enum: EquipmentFamily::CATEGORIES),
        new OA\Property(property: 'criticality', type: 'string', enum: EquipmentFamily::CRITICALITIES),
        new OA\Property(property: 'preventive_lead_pct', description: 'Margem de antecedência do pré-alerta (RN-001), em %. Número JSON, até 2 casas.', type: 'number', format: 'float', maximum: 100, exclusiveMinimum: true, minimum: 0, example: 87.5),
        new OA\Property(property: 'tolerance_km', type: 'integer', minimum: 0, nullable: true),
        new OA\Property(property: 'tolerance_hours', type: 'integer', minimum: 0, nullable: true),
        new OA\Property(property: 'tolerance_days', type: 'integer', minimum: 0, nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'deleted_at', description: 'Sempre presente; null quando ativo.', type: 'string', format: 'date-time', nullable: true),
    ],
)]
class EquipmentFamilyResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category,
            'criticality' => $this->criticality,
            'preventive_lead_pct' => $this->preventive_lead_pct,
            'tolerance_km' => $this->tolerance_km,
            'tolerance_hours' => $this->tolerance_hours,
            'tolerance_days' => $this->tolerance_days,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }
}
