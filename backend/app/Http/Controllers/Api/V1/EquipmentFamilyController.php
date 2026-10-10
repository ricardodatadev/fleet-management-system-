<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\CrudActions;
use App\Http\Controllers\Controller;
use App\Http\Requests\EquipmentFamilies\EquipmentFamilyRequest;
use App\Http\Resources\EquipmentFamilyResource;
use App\Models\EquipmentFamily;
use App\Support\Api\ApiResponse;
use App\Support\Api\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'EquipmentFamilyInput',
    description: 'Create: code, name e category obrigatórios; criticality (padrão medium) e preventive_lead_pct (padrão 90) opcionais. Update (PUT = PATCH): parcial. `code` é gravado sem espaços nas pontas e em maiúsculas. `preventive_lead_pct` em (0, 100] com até 2 casas; tolerâncias inteiras ≥ 0 ou null.',
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 30, example: 'CAM-PESADO'),
        new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'Caminhões pesados'),
        new OA\Property(property: 'category', type: 'string', enum: EquipmentFamily::CATEGORIES),
        new OA\Property(property: 'criticality', type: 'string', enum: EquipmentFamily::CRITICALITIES, default: 'medium'),
        new OA\Property(property: 'preventive_lead_pct', type: 'number', format: 'float', maximum: 100, exclusiveMinimum: true, minimum: 0, default: 90, example: 87.5),
        new OA\Property(property: 'tolerance_km', type: 'integer', minimum: 0, nullable: true, example: 500),
        new OA\Property(property: 'tolerance_hours', type: 'integer', minimum: 0, nullable: true, example: 20),
        new OA\Property(property: 'tolerance_days', type: 'integer', minimum: 0, nullable: true, example: 7),
        new OA\Property(property: 'is_active', type: 'boolean', default: true),
    ],
)]
#[OA\Schema(
    schema: 'EquipmentFamilyEnvelope',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/Envelope'),
        new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/EquipmentFamily')]),
    ],
)]
class EquipmentFamilyController extends Controller
{
    use CrudActions;

    #[OA\Get(
        path: '/equipment-families',
        operationId: 'equipmentFamiliesIndex',
        summary: 'Lista famílias de equipamento',
        description: 'Permissão: equipment_families.view (M, L e A; sem escopo de filial). Busca `q` em code e name. Ordenação: code, name, category, criticality (`-` = desc; padrão code); outro campo → 422.',
        tags: ['Famílias de equipamento'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/Page'),
            new OA\Parameter(ref: '#/components/parameters/PerPage'),
            new OA\Parameter(ref: '#/components/parameters/Search'),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', example: '-created_at,name')),
            new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'string', enum: EquipmentFamily::CATEGORIES)),
            new OA\Parameter(name: 'criticality', in: 'query', schema: new OA\Schema(type: 'string', enum: EquipmentFamily::CRITICALITIES)),
            new OA\Parameter(ref: '#/components/parameters/IsActive'),
            new OA\Parameter(ref: '#/components/parameters/WithTrashed'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista paginada.', content: new OA\JsonContent(allOf: [
                new OA\Schema(ref: '#/components/schemas/Envelope'),
                new OA\Schema(required: ['meta'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/EquipmentFamily'))]),
            ])),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $paginator = ListQuery::for($request, EquipmentFamily::query())
            ->search(['code', 'name'])
            ->filters([
                'category' => ['string', Rule::in(EquipmentFamily::CATEGORIES)],
                'criticality' => ['string', Rule::in(EquipmentFamily::CRITICALITIES)],
                'is_active' => ['boolean'],
            ])
            ->sortable(['code', 'name', 'category', 'criticality'], 'code')
            ->paginate();

        return ApiResponse::paginated($paginator, EquipmentFamilyResource::class);
    }

    #[OA\Post(
        path: '/equipment-families',
        operationId: 'equipmentFamiliesStore',
        summary: 'Cria família de equipamento',
        description: 'Permissão: equipment_families.manage. `code` duplicado entre registros ativos → 422; preventive_lead_pct fora de (0, 100] ou tolerância negativa → 422.',
        tags: ['Famílias de equipamento'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EquipmentFamilyInput')),
        responses: [
            new OA\Response(response: 201, description: 'Criada (criticality=medium e preventive_lead_pct=90 quando omitidos).', content: new OA\JsonContent(ref: '#/components/schemas/EquipmentFamilyEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function store(EquipmentFamilyRequest $request): JsonResponse
    {
        $family = $this->persist(fn () => EquipmentFamily::query()->create($request->validated()));

        return ApiResponse::item(new EquipmentFamilyResource($family->refresh()), __('api.created'), 201);
    }

    #[OA\Get(
        path: '/equipment-families/{equipment_family}',
        operationId: 'equipmentFamiliesShow',
        summary: 'Detalha família de equipamento',
        description: 'Permissão: equipment_families.view. Família excluída → 404.',
        tags: ['Famílias de equipamento'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'equipment_family', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Família.', content: new OA\JsonContent(ref: '#/components/schemas/EquipmentFamilyEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function show(EquipmentFamily $equipmentFamily): JsonResponse
    {
        Gate::authorize('view', $equipmentFamily);

        return ApiResponse::item(new EquipmentFamilyResource($equipmentFamily));
    }

    #[OA\Put(
        path: '/equipment-families/{equipment_family}',
        operationId: 'equipmentFamiliesUpdate',
        summary: 'Atualiza família de equipamento (parcial; PUT = PATCH)',
        description: 'Permissão: equipment_families.manage. Só os campos enviados são validados e gravados.',
        tags: ['Famílias de equipamento'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'equipment_family', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EquipmentFamilyInput')),
        responses: [
            new OA\Response(response: 200, description: 'Atualizada.', content: new OA\JsonContent(ref: '#/components/schemas/EquipmentFamilyEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    #[OA\Patch(
        path: '/equipment-families/{equipment_family}',
        operationId: 'equipmentFamiliesPatch',
        summary: 'Atualiza família de equipamento (parcial; igual ao PUT)',
        tags: ['Famílias de equipamento'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'equipment_family', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EquipmentFamilyInput')),
        responses: [
            new OA\Response(response: 200, description: 'Atualizada.', content: new OA\JsonContent(ref: '#/components/schemas/EquipmentFamilyEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function update(EquipmentFamilyRequest $request, EquipmentFamily $equipmentFamily): JsonResponse
    {
        Gate::authorize('update', $equipmentFamily);
        $this->persist(fn () => $equipmentFamily->update($request->validated()));

        return ApiResponse::item(new EquipmentFamilyResource($equipmentFamily->refresh()), __('api.updated'));
    }

    #[OA\Delete(
        path: '/equipment-families/{equipment_family}',
        operationId: 'equipmentFamiliesDestroy',
        summary: 'Exclui família de equipamento (soft delete)',
        description: 'Permissão: equipment_families.manage. 409 (`errors.dependents=["equipments"]`) se houver equipamentos ativos. Exclusão concorrente de família já excluída → 404.',
        tags: ['Famílias de equipamento'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'equipment_family', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Excluída (`data` null).', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, description: 'Dependentes ativos.', content: new OA\JsonContent(ref: '#/components/schemas/DeleteConflictEnvelope')),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function destroy(EquipmentFamily $equipmentFamily): JsonResponse
    {
        Gate::authorize('delete', $equipmentFamily);
        $this->softDeleteGuarded($equipmentFamily);

        return ApiResponse::success(null, __('api.deleted'));
    }

    #[OA\Post(
        path: '/equipment-families/{equipment_family}/restore',
        operationId: 'equipmentFamiliesRestore',
        summary: 'Restaura família de equipamento excluída',
        description: 'Permissão: equipment_families.manage. 409 se não estiver excluída ou se o código já estiver em uso por uma família ativa.',
        tags: ['Famílias de equipamento'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'equipment_family', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Restaurada.', content: new OA\JsonContent(ref: '#/components/schemas/EquipmentFamilyEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/Conflict'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function restore(EquipmentFamily $equipmentFamily): JsonResponse
    {
        Gate::authorize('restore', $equipmentFamily);
        $restored = $this->restoreGuarded($equipmentFamily);

        return ApiResponse::item(new EquipmentFamilyResource($restored), __('api.restored'));
    }
}
