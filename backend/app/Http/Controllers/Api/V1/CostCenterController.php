<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\CrudActions;
use App\Http\Controllers\Controller;
use App\Http\Requests\CostCenters\CostCenterRequest;
use App\Http\Resources\CostCenterResource;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Support\Api\ApiResponse;
use App\Support\Api\ListQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CostCenterInput',
    description: 'Create: code e name obrigatórios. Update (PUT = PATCH): parcial. `code` é gravado sem espaços nas pontas e em maiúsculas. `branch_id` deve ser filial existente e não excluída (inativa é aceita); null = sem filial.',
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 30, example: 'CC-0101'),
        new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'Manutenção pesada'),
        new OA\Property(property: 'branch_id', type: 'integer', nullable: true, example: 1),
        new OA\Property(property: 'is_active', type: 'boolean', default: true),
    ],
)]
#[OA\Schema(
    schema: 'CostCenterEnvelope',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/Envelope'),
        new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CostCenter')]),
    ],
)]
class CostCenterController extends Controller
{
    use CrudActions;

    #[OA\Get(
        path: '/cost-centers',
        operationId: 'costCentersIndex',
        summary: 'Lista centros de custo',
        description: 'Permissão: cost_centers.view (L e A). Escopo: não-admin vê os da própria filial + os sem filial. Ordenação: code, name, is_active, created_at, updated_at (`-` = desc; padrão code).',
        tags: ['Centros de custo'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/Page'),
            new OA\Parameter(ref: '#/components/parameters/PerPage'),
            new OA\Parameter(ref: '#/components/parameters/Search'),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', example: '-created_at,name')),
            new OA\Parameter(name: 'branch_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(ref: '#/components/parameters/IsActive'),
            new OA\Parameter(ref: '#/components/parameters/WithTrashed'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista paginada.', content: new OA\JsonContent(allOf: [
                new OA\Schema(ref: '#/components/schemas/Envelope'),
                new OA\Schema(required: ['meta'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CostCenter'))]),
            ])),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $paginator = ListQuery::for($request, CostCenter::query()->with('branch'))
            ->search(['code', 'name'])
            ->filters(['branch_id' => ['integer'], 'is_active' => ['boolean']])
            ->sortable(['code', 'name', 'is_active', 'created_at', 'updated_at'], 'code')
            ->paginate();

        return ApiResponse::paginated($paginator, CostCenterResource::class);
    }

    #[OA\Post(
        path: '/cost-centers',
        operationId: 'costCentersStore',
        summary: 'Cria centro de custo',
        description: 'Permissão: cost_centers.manage. `code` duplicado entre registros ativos → 422; filial inexistente ou excluída → 422.',
        tags: ['Centros de custo'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CostCenterInput')),
        responses: [
            new OA\Response(response: 201, description: 'Criada.', content: new OA\JsonContent(ref: '#/components/schemas/CostCenterEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function store(CostCenterRequest $request): JsonResponse
    {
        $costCenter = $this->persist(function () use ($request) {
            $this->lockBranch($request->validated('branch_id'));

            return CostCenter::query()->create($request->validated());
        });

        return ApiResponse::item(new CostCenterResource($costCenter->refresh()->load('branch')), __('api.created'), 201);
    }

    #[OA\Get(
        path: '/cost-centers/{cost_center}',
        operationId: 'costCentersShow',
        summary: 'Detalha centro de custo',
        description: 'Permissão: cost_centers.view. Excluído ou fora do escopo de filial → 404.',
        tags: ['Centros de custo'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'cost_center', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Filial.', content: new OA\JsonContent(ref: '#/components/schemas/CostCenterEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function show(CostCenter $costCenter): JsonResponse
    {
        Gate::authorize('view', $costCenter);

        return ApiResponse::item(new CostCenterResource($costCenter->load('branch')));
    }

    #[OA\Put(
        path: '/cost-centers/{cost_center}',
        operationId: 'costCentersUpdate',
        summary: 'Atualiza centro de custo (parcial; PUT = PATCH)',
        description: 'Permissão: cost_centers.manage. Só os campos enviados são validados e gravados.',
        tags: ['Centros de custo'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'cost_center', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CostCenterInput')),
        responses: [
            new OA\Response(response: 200, description: 'Atualizada.', content: new OA\JsonContent(ref: '#/components/schemas/CostCenterEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    #[OA\Patch(
        path: '/cost-centers/{cost_center}',
        operationId: 'costCentersPatch',
        summary: 'Atualiza centro de custo (parcial; igual ao PUT)',
        tags: ['Centros de custo'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'cost_center', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CostCenterInput')),
        responses: [
            new OA\Response(response: 200, description: 'Atualizada.', content: new OA\JsonContent(ref: '#/components/schemas/CostCenterEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function update(CostCenterRequest $request, CostCenter $costCenter): JsonResponse
    {
        Gate::authorize('update', $costCenter);
        $this->persist(function () use ($request, $costCenter) {
            if ($request->has('branch_id')) {
                $this->lockBranch($request->validated('branch_id'));
            }
            $costCenter->update($request->validated());
        });

        return ApiResponse::item(new CostCenterResource($costCenter->refresh()->load('branch')), __('api.updated'));
    }

    #[OA\Delete(
        path: '/cost-centers/{cost_center}',
        operationId: 'costCentersDestroy',
        summary: 'Exclui centro de custo (soft delete)',
        description: 'Permissão: cost_centers.manage. 409 se houver equipamentos ou colaboradores ativos vinculados (regras ativadas nas F1-14/15).',
        tags: ['Centros de custo'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'cost_center', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Excluída (`data` null).', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, description: 'Dependentes ativos.', content: new OA\JsonContent(ref: '#/components/schemas/DeleteConflictEnvelope')),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function destroy(CostCenter $costCenter): JsonResponse
    {
        Gate::authorize('delete', $costCenter);
        $this->softDeleteGuarded($costCenter);

        return ApiResponse::success(null, __('api.deleted'));
    }

    #[OA\Post(
        path: '/cost-centers/{cost_center}/restore',
        operationId: 'costCentersRestore',
        summary: 'Restaura centro de custo excluído',
        description: 'Permissão: cost_centers.manage. 409 se não estiver excluído, se o código já estiver em uso por um centro de custo ativo ou se a filial vinculada estiver excluída.',
        tags: ['Centros de custo'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'cost_center', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Restaurada.', content: new OA\JsonContent(ref: '#/components/schemas/CostCenterEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/Conflict'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function restore(CostCenter $costCenter): JsonResponse
    {
        Gate::authorize('restore', $costCenter);
        $restored = $this->restoreGuarded($costCenter, function (Model $locked) {
            $branchId = $locked->getAttribute('branch_id');

            return $branchId !== null && Branch::query()->whereKey($branchId)->sharedLock()->first(['id']) === null
                ? __('api.restore_branch_deleted')
                : null;
        });

        return ApiResponse::item(new CostCenterResource($restored->load('branch')), __('api.restored'));
    }
}
