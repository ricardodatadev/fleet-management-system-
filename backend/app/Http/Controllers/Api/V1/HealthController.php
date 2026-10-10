<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use App\Support\Api\HealthChecker;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'HealthData',
    required: ['app', 'db', 'redis', 'version'],
    properties: [
        new OA\Property(property: 'app', type: 'string', enum: ['up']),
        new OA\Property(property: 'db', type: 'string', enum: ['up', 'down']),
        new OA\Property(property: 'redis', type: 'string', enum: ['up', 'down']),
        new OA\Property(property: 'version', type: 'string', example: '0.1.0'),
    ],
)]
class HealthController extends Controller
{
    #[OA\Get(
        path: '/health',
        operationId: 'health',
        summary: 'Saúde da aplicação (db e redis)',
        description: 'Público e fora do rate limit. 503 se db ou redis estiverem fora (o corpo traz o estado de cada componente em `data`).',
        tags: ['Infra'],
        security: [],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Todos os componentes ativos.',
                content: new OA\JsonContent(allOf: [
                    new OA\Schema(ref: '#/components/schemas/Envelope'),
                    new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HealthData')]),
                ]),
            ),
            new OA\Response(
                response: 503,
                description: 'Algum componente indisponível.',
                content: new OA\JsonContent(allOf: [
                    new OA\Schema(ref: '#/components/schemas/ErrorEnvelope'),
                    new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HealthData')]),
                ]),
            ),
        ],
    )]
    public function __invoke(HealthChecker $checker): JsonResponse
    {
        $db = $checker->database();
        $redis = $checker->redis();

        $data = [
            'app' => 'up',
            'db' => $db ? 'up' : 'down',
            'redis' => $redis ? 'up' : 'down',
            'version' => config('app.version'),
        ];

        if (! $db || ! $redis) {
            return ApiResponse::error(__('api.health_down'), 503, null, $data);
        }

        return ApiResponse::success($data);
    }
}
