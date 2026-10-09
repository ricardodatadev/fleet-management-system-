<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\CrudActions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branches\BranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Support\Api\ApiResponse;
use App\Support\Api\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'BranchInput',
    description: 'Create: code, name e type obrigatórios. Update (PUT = PATCH): parcial, só os campos enviados. `code` é gravado sem espaços nas pontas e em maiúsculas; `state` em maiúsculas.',
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 20, example: 'FIL-001'),
        new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'Matriz'),
        new OA\Property(property: 'type', type: 'string', enum: Branch::TYPES),
        new OA\Property(property: 'city', type: 'string', maxLength: 80, nullable: true),
        new OA\Property(property: 'state', type: 'string', enum: Branch::STATES, nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', default: true),
    ],
)]
#[OA\Schema(
    schema: 'BranchEnvelope',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/Envelope'),
        new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Branch')]),
    ],
)]
class BranchController extends Controller
{
    use CrudActions;

    #[OA\Get(
        path: '/branches',
        operationId: 'branchesIndex',
        summary: 'Lista filiais',
        description: 'Permissão: branches.view (todos os perfis; sem escopo de filial). Ordenação: code, name, type, is_active, created_at, updated_at (`-` = desc; padrão code).',
        tags: ['Filiais'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/Page'),
            new OA\Parameter(ref: '#/components/parameters/PerPage'),
            new OA\Parameter(ref: '#/components/parameters/Search'),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', example: '-created_at,name')),
            new OA\Parameter(name: 'type', in: 'query', schema: new OA\Schema(type: 'string', enum: Branch::TYPES)),
            new OA\Parameter(ref: '#/components/parameters/IsActive'),
            new OA\Parameter(ref: '#/components/parameters/WithTrashed'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista paginada.', content: new OA\JsonContent(allOf: [
                new OA\Schema(ref: '#/components/schemas/Envelope'),
                new OA\Schema(required: ['meta'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Branch'))]),
            ])),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $paginator = ListQuery::for($request, Branch::query())
            ->search(['code', 'name'])
            ->filters(['type' => ['string', Rule::in(Branch::TYPES)], 'is_active' => ['boolean']])
            ->sortable(['code', 'name', 'type', 'is_active', 'created_at', 'updated_at'], 'code')
            ->paginate();

        return ApiResponse::paginated($paginator, BranchResource::class);
    }

    #[OA\Post(
        path: '/branches',
        operationId: 'branchesStore',
        summary: 'Cria filial',
        description: 'Permissão: branches.manage. `code` duplicado entre registros ativos → 422.',
        tags: ['Filiais'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BranchInput')),
        responses: [
            new OA\Response(response: 201, description: 'Criada.', content: new OA\JsonContent(ref: '#/components/schemas/BranchEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function store(BranchRequest $request): JsonResponse
    {
        $branch = $this->persist(fn () => Branch::query()->create($request->validated()));

        return ApiResponse::item(new BranchResource($branch->refresh()), __('api.created'), 201);
    }

    #[OA\Get(
        path: '/branches/{branch}',
        operationId: 'branchesShow',
        summary: 'Detalha filial',
        description: 'Permissão: branches.view. Filial excluída → 404.',
        tags: ['Filiais'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'branch', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Filial.', content: new OA\JsonContent(ref: '#/components/schemas/BranchEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function show(Branch $branch): JsonResponse
    {
        Gate::authorize('view', $branch);

        return ApiResponse::item(new BranchResource($branch));
    }

    #[OA\Put(
        path: '/branches/{branch}',
        operationId: 'branchesUpdate',
        summary: 'Atualiza filial (parcial; PUT = PATCH)',
        description: 'Permissão: branches.manage. Só os campos enviados são validados e gravados.',
        tags: ['Filiais'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'branch', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BranchInput')),
        responses: [
            new OA\Response(response: 200, description: 'Atualizada.', content: new OA\JsonContent(ref: '#/components/schemas/BranchEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    #[OA\Patch(
        path: '/branches/{branch}',
        operationId: 'branchesPatch',
        summary: 'Atualiza filial (parcial; igual ao PUT)',
        tags: ['Filiais'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'branch', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BranchInput')),
        responses: [
            new OA\Response(response: 200, description: 'Atualizada.', content: new OA\JsonContent(ref: '#/components/schemas/BranchEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function update(BranchRequest $request, Branch $branch): JsonResponse
    {
        Gate::authorize('update', $branch);
        $this->persist(fn () => $branch->update($request->validated()));

        return ApiResponse::item(new BranchResource($branch->refresh()), __('api.updated'));
    }

    #[OA\Delete(
        path: '/branches/{branch}',
        operationId: 'branchesDestroy',
        summary: 'Exclui filial (soft delete)',
        description: 'Permissão: branches.manage. 409 se houver centros de custo ou usuários ativos (não excluídos) vinculados; equipamentos e colaboradores entram nas F1-14/15.',
        tags: ['Filiais'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'branch', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Excluída (`data` null).', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, description: 'Dependentes ativos.', content: new OA\JsonContent(ref: '#/components/schemas/DeleteConflictEnvelope')),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function destroy(Branch $branch): JsonResponse
    {
        Gate::authorize('delete', $branch);
        $this->softDeleteGuarded($branch);

        return ApiResponse::success(null, __('api.deleted'));
    }

    #[OA\Post(
        path: '/branches/{branch}/restore',
        operationId: 'branchesRestore',
        summary: 'Restaura filial excluída',
        description: 'Permissão: branches.manage. 409 se não estiver excluída ou se o código já estiver em uso por uma filial ativa.',
        tags: ['Filiais'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'branch', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Restaurada.', content: new OA\JsonContent(ref: '#/components/schemas/BranchEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/Conflict'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function restore(Branch $branch): JsonResponse
    {
        Gate::authorize('restore', $branch);
        $restored = $this->restoreGuarded($branch);

        return ApiResponse::item(new BranchResource($restored), __('api.restored'));
    }
}
