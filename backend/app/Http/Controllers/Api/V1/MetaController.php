<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
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
                            required: ['branch_types'],
                            properties: [new OA\Property(property: 'branch_types', type: 'array', items: new OA\Items(type: 'string', enum: Branch::TYPES))],
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
        ]]);
    }
}
