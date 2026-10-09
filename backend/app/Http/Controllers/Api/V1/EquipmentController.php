<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\CrudActions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Equipments\EquipmentRequest;
use App\Http\Resources\EquipmentResource;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\EquipmentFamily;
use App\Models\Scopes\BranchScope;
use App\Support\Api\ApiResponse;
use App\Support\Api\FieldMessage;
use App\Support\Api\ListQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'EquipmentInput',
    description: 'Create: code, name, family_id, branch_id e cost_center_id obrigatórios. Update (PUT = PATCH): parcial. `code` em trim + maiúsculas. `plate`: maiúsculas, sem espaços nem hífen, depois `^[A-Z0-9]{5,8}$`; única entre não excluídos sobre o valor normalizado. `year` entre 1950 e o ano atual + 1. `cost_center_id`: não excluído e da mesma filial do equipamento ou sem filial. `responsible_employee_id`: colaborador não excluído da mesma filial. Família, filial, centro de custo e responsável existentes e não excluídos.',
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 30, example: 'CAM-001'),
        new OA\Property(property: 'name', type: 'string', maxLength: 150, example: 'Caminhão basculante 01'),
        new OA\Property(property: 'family_id', type: 'integer', example: 1),
        new OA\Property(property: 'branch_id', type: 'integer', example: 1),
        new OA\Property(property: 'cost_center_id', type: 'integer', example: 1),
        new OA\Property(property: 'responsible_employee_id', type: 'integer', nullable: true),
        new OA\Property(property: 'plate', type: 'string', nullable: true, example: 'abc-1d23'),
        new OA\Property(property: 'serial_number', type: 'string', maxLength: 60, nullable: true),
        new OA\Property(property: 'manufacturer', type: 'string', maxLength: 80, nullable: true),
        new OA\Property(property: 'model', type: 'string', maxLength: 80, nullable: true),
        new OA\Property(property: 'year', type: 'integer', minimum: 1950, nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: Equipment::STATUSES, default: 'active'),
        new OA\Property(property: 'criticality_override', type: 'string', enum: EquipmentFamily::CRITICALITIES, nullable: true),
        new OA\Property(property: 'odometer_km', type: 'number', format: 'float', minimum: 0, default: 0),
        new OA\Property(property: 'hour_meter', type: 'number', format: 'float', minimum: 0, default: 0),
        new OA\Property(property: 'acquisition_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'acquisition_value', type: 'number', format: 'float', minimum: 0, nullable: true),
        new OA\Property(property: 'notes', type: 'string', maxLength: 5000, nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'EquipmentEnvelope',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/Envelope'),
        new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Equipment')]),
    ],
)]
class EquipmentController extends Controller
{
    use CrudActions;

    /** Índices únicos parciais → campo do 422 quando a corrida passa pela validação. */
    private const UNIQUE_INDEXES = ['equipments_code_unique' => 'code', 'equipments_plate_unique' => 'plate'];

    private const RELATIONS = ['family', 'branch', 'costCenter', 'responsibleEmployee'];

    #[OA\Get(
        path: '/equipments',
        operationId: 'equipmentsIndex',
        summary: 'Lista equipamentos',
        description: 'Permissão: equipments.view (todos os perfis). Escopo: não-admin vê só a própria filial. Busca `q` em code, name e plate. Ordenação: code, name, plate, status, year, acquisition_date (`-` = desc; padrão code).',
        tags: ['Equipamentos'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/Page'),
            new OA\Parameter(ref: '#/components/parameters/PerPage'),
            new OA\Parameter(ref: '#/components/parameters/Search'),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', example: '-year,code')),
            new OA\Parameter(name: 'family_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'branch_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'cost_center_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: Equipment::STATUSES)),
            new OA\Parameter(name: 'responsible_employee_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(ref: '#/components/parameters/WithTrashed'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista paginada.', content: new OA\JsonContent(allOf: [
                new OA\Schema(ref: '#/components/schemas/Envelope'),
                new OA\Schema(required: ['meta'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Equipment'))]),
            ])),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $paginator = ListQuery::for($request, Equipment::query()->with(self::RELATIONS))
            ->search(['code', 'name', 'plate'])
            ->filters([
                'family_id' => ['integer'],
                'branch_id' => ['integer'],
                'cost_center_id' => ['integer'],
                'status' => ['string', Rule::in(Equipment::STATUSES)],
                'responsible_employee_id' => ['integer'],
            ])
            ->sortable(['code', 'name', 'plate', 'status', 'year', 'acquisition_date'], 'code')
            ->paginate();

        return ApiResponse::paginated($paginator, EquipmentResource::class);
    }

    #[OA\Post(
        path: '/equipments',
        operationId: 'equipmentsStore',
        summary: 'Cria equipamento',
        description: 'Permissão: equipments.manage. 422: code ou placa duplicados entre ativos; placa fora do formato; família, filial, centro de custo ou responsável inexistente ou excluído; centro de custo de outra filial; responsável de outra filial; ano fora de 1950..ano atual + 1.',
        tags: ['Equipamentos'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EquipmentInput')),
        responses: [
            new OA\Response(response: 201, description: 'Criado (status=active, odômetro e horímetro 0 quando omitidos).', content: new OA\JsonContent(ref: '#/components/schemas/EquipmentEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function store(EquipmentRequest $request): JsonResponse
    {
        $equipment = $this->persist(function () use ($request) {
            $this->lockRelated($request->validated(), null);

            return Equipment::query()->create($request->validated());
        }, self::UNIQUE_INDEXES);

        return ApiResponse::item(new EquipmentResource($equipment->refresh()->load(self::RELATIONS)), __('api.created'), 201);
    }

    #[OA\Get(
        path: '/equipments/{equipment}',
        operationId: 'equipmentsShow',
        summary: 'Detalha equipamento',
        description: 'Permissão: equipments.view. Excluído ou de outra filial (não-admin) → 404.',
        tags: ['Equipamentos'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'equipment', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Equipamento.', content: new OA\JsonContent(ref: '#/components/schemas/EquipmentEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function show(Equipment $equipment): JsonResponse
    {
        Gate::authorize('view', $equipment);

        return ApiResponse::item(new EquipmentResource($equipment->load(self::RELATIONS)));
    }

    #[OA\Put(
        path: '/equipments/{equipment}',
        operationId: 'equipmentsUpdate',
        summary: 'Atualiza equipamento (parcial; PUT = PATCH)',
        description: 'Permissão: equipments.manage. Só os campos enviados são validados e gravados; as regras de filial valem também ao trocar a filial do equipamento.',
        tags: ['Equipamentos'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'equipment', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EquipmentInput')),
        responses: [
            new OA\Response(response: 200, description: 'Atualizado.', content: new OA\JsonContent(ref: '#/components/schemas/EquipmentEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    #[OA\Patch(
        path: '/equipments/{equipment}',
        operationId: 'equipmentsPatch',
        summary: 'Atualiza equipamento (parcial; igual ao PUT)',
        tags: ['Equipamentos'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'equipment', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EquipmentInput')),
        responses: [
            new OA\Response(response: 200, description: 'Atualizado.', content: new OA\JsonContent(ref: '#/components/schemas/EquipmentEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function update(EquipmentRequest $request, Equipment $equipment): JsonResponse
    {
        Gate::authorize('update', $equipment);
        $this->persist(function () use ($request, $equipment) {
            $this->lockRelated($request->validated(), $equipment);
            $equipment->update($request->validated());
        }, self::UNIQUE_INDEXES);

        return ApiResponse::item(new EquipmentResource($equipment->refresh()->load(self::RELATIONS)), __('api.updated'));
    }

    #[OA\Delete(
        path: '/equipments/{equipment}',
        operationId: 'equipmentsDestroy',
        summary: 'Exclui equipamento (soft delete)',
        description: 'Permissão: equipments.manage. Exclusão concorrente de equipamento já excluído → 404.',
        tags: ['Equipamentos'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'equipment', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Excluído (`data` null).', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function destroy(Equipment $equipment): JsonResponse
    {
        Gate::authorize('delete', $equipment);
        $this->softDeleteGuarded($equipment);

        return ApiResponse::success(null, __('api.deleted'));
    }

    #[OA\Post(
        path: '/equipments/{equipment}/restore',
        operationId: 'equipmentsRestore',
        summary: 'Restaura equipamento excluído',
        description: 'Permissão: equipments.manage. 409 se não estiver excluído, se o code ou a placa foram reutilizados, se a filial, a família ou o centro de custo vinculado está excluído (ou o centro de custo mudou para outra filial), ou se o responsável está excluído ou em outra filial.',
        tags: ['Equipamentos'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'equipment', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Restaurado.', content: new OA\JsonContent(ref: '#/components/schemas/EquipmentEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/Conflict'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function restore(Equipment $equipment): JsonResponse
    {
        Gate::authorize('restore', $equipment);
        $restored = $this->restoreGuarded($equipment, fn (Model $locked) => $locked instanceof Equipment ? $this->restoreConflict($locked) : null, ['code', 'plate']);

        return ApiResponse::item(new EquipmentResource($restored->load(self::RELATIONS)), __('api.restored'));
    }

    /**
     * Travas e consistências de filial (D.2), na transação da gravação. Filial, família, centro de custo e
     * responsável são travados com FOR SHARE: a exclusão deles (FOR UPDATE) e a troca de filial do
     * centro de custo/colaborador esperam esta transação. Só checa o que o payload muda.
     *
     * @param  array<string, mixed>  $data
     */
    private function lockRelated(array $data, ?Equipment $equipment): void
    {
        $branchChanged = array_key_exists('branch_id', $data);
        $branchId = $branchChanged ? $data['branch_id'] : $equipment?->branch_id;
        if ($branchChanged) {
            $this->lockBranch($branchId);
        }

        if (array_key_exists('family_id', $data)
            && EquipmentFamily::query()->whereKey($data['family_id'])->sharedLock()->first(['id']) === null) {
            throw ValidationException::withMessages(['family_id' => FieldMessage::for('exists', 'family_id')]);
        }

        $costCenterId = array_key_exists('cost_center_id', $data) ? $data['cost_center_id'] : $equipment?->cost_center_id;
        if ($costCenterId !== null && ($branchChanged || array_key_exists('cost_center_id', $data))) {
            $costCenter = CostCenter::query()->withoutGlobalScope(BranchScope::class)->whereKey($costCenterId)->sharedLock()->first(['id', 'branch_id']);
            if ($costCenter === null) {
                throw ValidationException::withMessages(['cost_center_id' => FieldMessage::for('exists', 'cost_center_id')]);
            }
            if ($costCenter->branch_id !== null && (int) $costCenter->branch_id !== (int) $branchId) {
                throw ValidationException::withMessages(['cost_center_id' => __('api.equipment_cost_center_branch_mismatch')]);
            }
        }

        $employeeId = array_key_exists('responsible_employee_id', $data) ? $data['responsible_employee_id'] : $equipment?->responsible_employee_id;
        if ($employeeId !== null && ($branchChanged || array_key_exists('responsible_employee_id', $data))) {
            $employee = Employee::query()->withoutGlobalScope(BranchScope::class)->whereKey($employeeId)->sharedLock()->first(['id', 'branch_id']);
            if ($employee === null) {
                throw ValidationException::withMessages(['responsible_employee_id' => FieldMessage::for('exists', 'responsible_employee_id')]);
            }
            if ((int) $employee->branch_id !== (int) $branchId) {
                throw ValidationException::withMessages(['responsible_employee_id' => __('api.equipment_responsible_branch_mismatch')]);
            }
        }
    }

    /** Regras do restore (409) além de code/placa reutilizados, com as linhas relacionadas travadas. */
    private function restoreConflict(Equipment $equipment): ?string
    {
        if (Branch::query()->whereKey($equipment->branch_id)->sharedLock()->first(['id']) === null) {
            return __('api.restore_branch_deleted');
        }
        if (EquipmentFamily::query()->whereKey($equipment->family_id)->sharedLock()->first(['id']) === null) {
            return __('api.restore_family_deleted');
        }
        $costCenter = CostCenter::query()->withoutGlobalScope(BranchScope::class)->whereKey($equipment->cost_center_id)->sharedLock()->first(['id', 'branch_id']);
        if ($costCenter === null) {
            return __('api.restore_cost_center_deleted');
        }
        if ($costCenter->branch_id !== null && (int) $costCenter->branch_id !== (int) $equipment->branch_id) {
            return __('api.equipment_cost_center_branch_mismatch');
        }
        if ($equipment->responsible_employee_id !== null) {
            $employee = Employee::query()->withoutGlobalScope(BranchScope::class)->whereKey($equipment->responsible_employee_id)->sharedLock()->first(['id', 'branch_id']);
            if ($employee === null || (int) $employee->branch_id !== (int) $equipment->branch_id) {
                return __('api.restore_responsible_unavailable');
            }
        }

        return null;
    }
}
