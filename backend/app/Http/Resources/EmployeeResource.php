<?php

namespace App\Http\Resources;

use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * @mixin Employee
 */
#[OA\Schema(
    schema: 'Employee',
    required: ['id', 'registration', 'name', 'job_type', 'branch', 'cost_center', 'user', 'phone', 'hired_at', 'cnh_number', 'cnh_category', 'cnh_expires_at', 'specialty', 'hourly_cost', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'registration', description: 'Matrícula (maiúsculas).', type: 'string', maxLength: 30, example: 'MAT-00123'),
        new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'João Pereira'),
        new OA\Property(property: 'job_type', type: 'string', enum: Employee::JOB_TYPES),
        new OA\Property(
            property: 'branch',
            type: 'object',
            required: ['id', 'code', 'name'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'code', type: 'string', example: 'FIL-001'),
                new OA\Property(property: 'name', type: 'string', example: 'Matriz'),
            ],
        ),
        new OA\Property(
            property: 'cost_center',
            type: 'object',
            nullable: true,
            required: ['id', 'code', 'name'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'code', type: 'string', example: 'CC-0101'),
                new OA\Property(property: 'name', type: 'string', example: 'Manutenção pesada'),
            ],
        ),
        new OA\Property(
            property: 'user',
            description: 'Usuário vinculado (1:1); null = sem acesso ao sistema.',
            type: 'object',
            nullable: true,
            required: ['id', 'name', 'email'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'name', type: 'string', example: 'João Pereira'),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'joao@example.com'),
            ],
        ),
        new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true),
        new OA\Property(property: 'hired_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'cnh_number', description: 'Só motorista.', type: 'string', maxLength: 20, nullable: true),
        new OA\Property(property: 'cnh_category', description: 'Só motorista.', type: 'string', enum: Employee::CNH_CATEGORIES, nullable: true),
        new OA\Property(property: 'cnh_expires_at', description: 'Só motorista.', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'specialty', description: 'Só mecânico.', type: 'string', maxLength: 80, nullable: true),
        new OA\Property(property: 'hourly_cost', description: 'Só mecânico. Custo/hora; número JSON, até 2 casas.', type: 'number', format: 'float', minimum: 0, nullable: true, example: 85.5),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'deleted_at', description: 'Sempre presente; null quando ativo.', type: 'string', format: 'date-time', nullable: true),
    ],
)]
class EmployeeResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'registration' => $this->registration,
            'name' => $this->name,
            'job_type' => $this->job_type,
            'branch' => BranchResource::ref($this->branch),
            'cost_center' => $this->costCenterRef($this->costCenter),
            'user' => $this->userRef($this->user),
            'phone' => $this->phone,
            'hired_at' => $this->hired_at?->format('Y-m-d'),
            'cnh_number' => $this->cnh_number,
            'cnh_category' => $this->cnh_category,
            'cnh_expires_at' => $this->cnh_expires_at?->format('Y-m-d'),
            'specialty' => $this->specialty,
            'hourly_cost' => $this->hourly_cost,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }

    /** Resumo do colaborador em /auth/me. */
    public static function summary(?Employee $employee): ?array
    {
        return $employee === null ? null : [
            'id' => $employee->id,
            'registration' => $employee->registration,
            'name' => $employee->name,
            'job_type' => $employee->job_type,
            'branch_id' => $employee->branch_id,
        ];
    }

    private function costCenterRef(?CostCenter $costCenter): ?array
    {
        return $costCenter === null ? null : ['id' => $costCenter->id, 'code' => $costCenter->code, 'name' => $costCenter->name];
    }

    private function userRef(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
    }
}
