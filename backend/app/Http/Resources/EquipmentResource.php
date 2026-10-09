<?php

namespace App\Http\Resources;

use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\EquipmentFamily;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * @mixin Equipment
 */
#[OA\Schema(
    schema: 'EquipmentRef',
    required: ['id', 'code', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'code', type: 'string', example: 'X-01'),
        new OA\Property(property: 'name', type: 'string', example: 'Nome'),
    ],
)]
#[OA\Schema(
    schema: 'Equipment',
    required: ['id', 'code', 'name', 'family', 'branch', 'cost_center', 'responsible_employee', 'plate', 'serial_number', 'manufacturer', 'model', 'year', 'status', 'criticality', 'criticality_source', 'criticality_override', 'odometer_km', 'hour_meter', 'acquisition_date', 'acquisition_value', 'notes', 'created_at', 'updated_at', 'deleted_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'code', type: 'string', maxLength: 30, example: 'CAM-001'),
        new OA\Property(property: 'name', type: 'string', maxLength: 150, example: 'Caminhão basculante 01'),
        new OA\Property(property: 'family', ref: '#/components/schemas/EquipmentRef'),
        new OA\Property(property: 'branch', ref: '#/components/schemas/EquipmentRef'),
        new OA\Property(property: 'cost_center', ref: '#/components/schemas/EquipmentRef'),
        new OA\Property(
            property: 'responsible_employee',
            type: 'object',
            nullable: true,
            required: ['id', 'registration', 'name'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'registration', type: 'string', example: 'MAT-00123'),
                new OA\Property(property: 'name', type: 'string', example: 'João Pereira'),
            ],
        ),
        new OA\Property(property: 'plate', description: 'Maiúsculas, sem espaços nem hífen.', type: 'string', pattern: '^[A-Z0-9]{5,8}$', nullable: true, example: 'ABC1D23'),
        new OA\Property(property: 'serial_number', type: 'string', maxLength: 60, nullable: true),
        new OA\Property(property: 'manufacturer', type: 'string', maxLength: 80, nullable: true),
        new OA\Property(property: 'model', type: 'string', maxLength: 80, nullable: true),
        new OA\Property(property: 'year', type: 'integer', minimum: 1950, nullable: true, example: 2022),
        new OA\Property(property: 'status', type: 'string', enum: Equipment::STATUSES),
        new OA\Property(property: 'criticality', description: 'Efetiva: override do equipamento ou a da família.', type: 'string', enum: EquipmentFamily::CRITICALITIES),
        new OA\Property(property: 'criticality_source', type: 'string', enum: ['family', 'override']),
        new OA\Property(property: 'criticality_override', type: 'string', enum: EquipmentFamily::CRITICALITIES, nullable: true),
        new OA\Property(property: 'odometer_km', description: 'Número JSON (1 casa).', type: 'number', format: 'float', minimum: 0, example: 125430.5),
        new OA\Property(property: 'hour_meter', description: 'Número JSON (1 casa).', type: 'number', format: 'float', minimum: 0, example: 3210),
        new OA\Property(property: 'acquisition_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'acquisition_value', description: 'Número JSON (2 casas).', type: 'number', format: 'float', minimum: 0, nullable: true, example: 450000),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'deleted_at', description: 'Sempre presente; null quando ativo.', type: 'string', format: 'date-time', nullable: true),
    ],
)]
class EquipmentResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'family' => $this->ref($this->family),
            'branch' => BranchResource::ref($this->branch),
            'cost_center' => $this->ref($this->costCenter),
            'responsible_employee' => $this->employeeRef($this->responsibleEmployee),
            'plate' => $this->plate,
            'serial_number' => $this->serial_number,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'year' => $this->year,
            'status' => $this->status,
            'criticality' => $this->effectiveCriticality(),
            'criticality_source' => $this->criticalitySource(),
            'criticality_override' => $this->criticality_override,
            'odometer_km' => $this->odometer_km,
            'hour_meter' => $this->hour_meter,
            'acquisition_date' => $this->acquisition_date?->format('Y-m-d'),
            'acquisition_value' => $this->acquisition_value,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }

    private function ref(EquipmentFamily|CostCenter|null $model): ?array
    {
        return $model === null ? null : ['id' => $model->id, 'code' => $model->code, 'name' => $model->name];
    }

    private function employeeRef(?Employee $employee): ?array
    {
        return $employee === null ? null : ['id' => $employee->id, 'registration' => $employee->registration, 'name' => $employee->name];
    }
}
