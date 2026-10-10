<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\EquipmentFamily;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class MetaController extends Controller
{
    /** Enums para selects; cada cadastro acrescenta os seus (roles, statuses, categories...). */
    #[OA\Get(
        path: '/meta/enums',
        operationId: 'metaEnums',
        summary: 'Enums e metadados para selects',
        description: 'Enums para selects; cresce com os cadastros. Perfil: qualquer autenticado.',
        tags: ['Infra'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Enums disponíveis.',
                content: new OA\JsonContent(allOf: [
                    new OA\Schema(ref: '#/components/schemas/Envelope'),
                    new OA\Schema(properties: [new OA\Property(
                        property: 'data',
                        type: 'object',
                        properties: [new OA\Property(
                            property: 'enums',
                            type: 'object',
                            required: ['branch_types', 'roles', 'equipment_categories', 'criticalities', 'job_types', 'cnh_categories', 'equipment_statuses'],
                            properties: [
                                new OA\Property(property: 'branch_types', type: 'array', items: new OA\Items(type: 'string', enum: Branch::TYPES)),
                                new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string', enum: ['operator', 'mechanic', 'leader', 'admin'])),
                                new OA\Property(property: 'equipment_categories', type: 'array', items: new OA\Items(type: 'string', enum: EquipmentFamily::CATEGORIES)),
                                new OA\Property(property: 'criticalities', type: 'array', items: new OA\Items(type: 'string', enum: EquipmentFamily::CRITICALITIES)),
                                new OA\Property(property: 'job_types', type: 'array', items: new OA\Items(type: 'string', enum: Employee::JOB_TYPES)),
                                new OA\Property(property: 'cnh_categories', type: 'array', items: new OA\Items(type: 'string', enum: Employee::CNH_CATEGORIES)),
                                new OA\Property(property: 'equipment_statuses', type: 'array', items: new OA\Items(type: 'string', enum: Equipment::STATUSES)),
                            ],
                        )],
                    )]),
                ]),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
            new OA\Response(response: 500, ref: '#/components/responses/ServerError'),
        ],
    )]
    public function enums(): JsonResponse
    {
        return ApiResponse::success(['enums' => [
            'branch_types' => Branch::TYPES,
            'roles' => Role::values(),
            'equipment_categories' => EquipmentFamily::CATEGORIES,
            'criticalities' => EquipmentFamily::CRITICALITIES,
            'job_types' => Employee::JOB_TYPES,
            'cnh_categories' => Employee::CNH_CATEGORIES,
            'equipment_statuses' => Equipment::STATUSES,
        ]]);
    }
}
