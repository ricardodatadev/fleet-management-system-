<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class MetaController extends Controller
{
    /** Esqueleto: os enums (roles, statuses, categories...) chegam com os cadastros. */
    #[OA\Get(
        path: '/meta/enums',
        operationId: 'metaEnums',
        summary: 'Enums e metadados para selects (esqueleto)',
        description: 'Esqueleto (vazio até os cadastros). Perfil: qualquer autenticado.',
        tags: ['Infra'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Enums disponíveis (vazio por enquanto).',
                content: new OA\JsonContent(allOf: [
                    new OA\Schema(ref: '#/components/schemas/Envelope'),
                    new OA\Schema(properties: [new OA\Property(
                        property: 'data',
                        type: 'object',
                        properties: [new OA\Property(property: 'enums', type: 'object')],
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
        return ApiResponse::success(['enums' => (object) []]);
    }
}
