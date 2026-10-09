<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * @mixin User
 */
#[OA\Schema(
    schema: 'AuthUser',
    required: ['id', 'name', 'username', 'email', 'role', 'branch'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Ana Souza'),
        new OA\Property(property: 'username', type: 'string', pattern: '^[a-z0-9]{3,30}$', example: 'anasouza'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ana@example.com'),
        new OA\Property(property: 'role', type: 'string', enum: ['operator', 'mechanic', 'leader', 'admin']),
        new OA\Property(
            property: 'branch',
            description: 'Filial do usuário; null = todas (somente admin).',
            type: 'object',
            nullable: true,
            required: ['id', 'code', 'name'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'code', type: 'string', example: 'FIL-001'),
                new OA\Property(property: 'name', type: 'string', example: 'Matriz'),
            ],
        ),
    ],
)]
class UserResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'role' => $this->role->value,
            'branch' => $this->branch === null ? null : [
                'id' => $this->branch->id,
                'code' => $this->branch->code,
                'name' => $this->branch->name,
            ],
        ];
    }
}
