<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Usuário no CRUD /users (contrato v1.3). Nunca inclui `password` nem `remember_token`.
 * O usuário de /auth/* usa UserResource (schema AuthUser).
 *
 * @mixin User
 */
#[OA\Schema(
    schema: 'User',
    required: ['id', 'name', 'username', 'email', 'role', 'branch', 'is_active', 'last_login_at', 'created_at', 'updated_at', 'deleted_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'Ana Souza'),
        new OA\Property(property: 'username', type: 'string', pattern: '^[a-z0-9]{3,30}$', example: 'anasouza'),
        new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 190, example: 'ana@example.com'),
        new OA\Property(property: 'role', type: 'string', enum: ['operator', 'mechanic', 'leader', 'admin']),
        new OA\Property(
            property: 'branch',
            description: 'Filial `{id, code, name}`; null = todas (somente admin).',
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
        new OA\Property(property: 'last_login_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'deleted_at', description: 'Sempre presente; null quando ativo.', type: 'string', format: 'date-time', nullable: true),
    ],
)]
class ManagedUserResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'role' => $this->role->value,
            'branch' => BranchResource::ref($this->branch),
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }
}
