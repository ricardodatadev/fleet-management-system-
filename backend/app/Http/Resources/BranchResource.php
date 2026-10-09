<?php

namespace App\Http\Resources;

use App\Models\Branch;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * @mixin Branch
 */
#[OA\Schema(
    schema: 'Branch',
    required: ['id', 'code', 'name', 'type', 'city', 'state', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'code', type: 'string', maxLength: 20, example: 'FIL-001'),
        new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'Matriz'),
        new OA\Property(property: 'type', type: 'string', enum: Branch::TYPES),
        new OA\Property(property: 'city', type: 'string', maxLength: 80, nullable: true, example: 'Goiânia'),
        new OA\Property(property: 'state', type: 'string', enum: Branch::STATES, nullable: true, example: 'GO'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'deleted_at', description: 'Sempre presente; null quando ativo.', type: 'string', format: 'date-time', nullable: true),
    ],
)]
class BranchResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'city' => $this->city,
            'state' => $this->state,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }

    /** Forma `{id, code, name}` usada ao embutir a filial em outros recursos. */
    public static function ref(?Branch $branch): ?array
    {
        return $branch === null ? null : ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name];
    }
}
