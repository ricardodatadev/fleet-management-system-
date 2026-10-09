<?php

namespace App\Http\Resources;

use App\Models\CostCenter;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * @mixin CostCenter
 */
#[OA\Schema(
    schema: 'CostCenter',
    required: ['id', 'code', 'name', 'branch', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'code', type: 'string', maxLength: 30, example: 'CC-0101'),
        new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'Manutenção pesada'),
        new OA\Property(
            property: 'branch',
            description: 'Filial `{id, code, name}`; null = sem filial (visível a todas).',
            type: 'object',
            nullable: true,
            required: ['id', 'code', 'name'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'code', type: 'string', example: 'FIL-001'),
                new OA\Property(property: 'name', type: 'string', example: 'Matriz'),
            ],
        ),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'deleted_at', description: 'Sempre presente; null quando ativo.', type: 'string', format: 'date-time', nullable: true),
    ],
)]
class CostCenterResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'branch' => BranchResource::ref($this->branch),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }
}
