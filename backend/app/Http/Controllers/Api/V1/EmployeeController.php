<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\CrudActions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\EmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\Scopes\BranchScope;
use App\Models\User;
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
    schema: 'EmployeeInput',
    description: 'Create: registration, name, job_type e branch_id obrigatórios. Update (PUT = PATCH): parcial. `registration` é gravada sem espaços nas pontas e em maiúsculas (única entre não excluídos). Campos de CNH só para `driver`; `specialty` e `hourly_cost` só para `mechanic` (valor em campo de outro tipo → 422). Ao trocar o `job_type`, os campos preenchidos do tipo anterior precisam vir como null no mesmo payload (senão 422). `cost_center_id`: não excluído e da mesma filial do colaborador ou sem filial. `user_id`: usuário não excluído e livre; se não for admin, precisa ser da mesma filial do colaborador.',
    properties: [
        new OA\Property(property: 'registration', type: 'string', maxLength: 30, example: 'MAT-00123'),
        new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'João Pereira'),
        new OA\Property(property: 'job_type', type: 'string', enum: Employee::JOB_TYPES),
        new OA\Property(property: 'branch_id', type: 'integer', example: 1),
        new OA\Property(property: 'cost_center_id', type: 'integer', nullable: true),
        new OA\Property(property: 'user_id', type: 'integer', nullable: true),
        new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true),
        new OA\Property(property: 'hired_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'cnh_number', type: 'string', maxLength: 20, nullable: true),
        new OA\Property(property: 'cnh_category', type: 'string', enum: Employee::CNH_CATEGORIES, nullable: true),
        new OA\Property(property: 'cnh_expires_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'specialty', type: 'string', maxLength: 80, nullable: true),
        new OA\Property(property: 'hourly_cost', type: 'number', format: 'float', minimum: 0, nullable: true, example: 85.5),
        new OA\Property(property: 'is_active', type: 'boolean', default: true),
    ],
)]
#[OA\Schema(
    schema: 'EmployeeEnvelope',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/Envelope'),
        new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Employee')]),
    ],
)]
class EmployeeController extends Controller
{
    use CrudActions;

    /** Índices únicos parciais → campo do 422 quando a corrida passa pela validação. */
    private const UNIQUE_INDEXES = ['employees_registration_unique' => 'registration', 'employees_user_id_unique' => 'user_id'];

    #[OA\Get(
        path: '/employees',
        operationId: 'employeesIndex',
        summary: 'Lista colaboradores',
        description: 'Permissão: employees.view (L e A). Escopo: L vê só a própria filial. Busca `q` em name e registration. Ordenação: name, registration, job_type, hired_at (`-` = desc; padrão name).',
        tags: ['Colaboradores'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/Page'),
            new OA\Parameter(ref: '#/components/parameters/PerPage'),
            new OA\Parameter(ref: '#/components/parameters/Search'),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', example: '-created_at,name')),
            new OA\Parameter(name: 'job_type', in: 'query', schema: new OA\Schema(type: 'string', enum: Employee::JOB_TYPES)),
            new OA\Parameter(name: 'branch_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(ref: '#/components/parameters/IsActive'),
            new OA\Parameter(ref: '#/components/parameters/WithTrashed'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista paginada.', content: new OA\JsonContent(allOf: [
                new OA\Schema(ref: '#/components/schemas/Envelope'),
                new OA\Schema(required: ['meta'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Employee'))]),
            ])),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $paginator = ListQuery::for($request, Employee::query()->with(['branch', 'costCenter', 'user']))
            ->search(['name', 'registration'])
            ->filters(['job_type' => ['string', Rule::in(Employee::JOB_TYPES)], 'branch_id' => ['integer'], 'is_active' => ['boolean']])
            ->sortable(['name', 'registration', 'job_type', 'hired_at'], 'name')
            ->paginate();

        return ApiResponse::paginated($paginator, EmployeeResource::class);
    }

    #[OA\Post(
        path: '/employees',
        operationId: 'employeesStore',
        summary: 'Cria colaborador',
        description: 'Permissão: employees.manage. 422: matrícula duplicada entre ativos; filial/centro de custo inexistente ou excluído; centro de custo de outra filial; usuário excluído, já vinculado ou (não-admin) de outra filial; campo fora do job_type.',
        tags: ['Colaboradores'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EmployeeInput')),
        responses: [
            new OA\Response(response: 201, description: 'Criado.', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function store(EmployeeRequest $request): JsonResponse
    {
        $employee = $this->persist(function () use ($request) {
            $this->lockRelated($request->validated(), null);

            return Employee::query()->create($request->validated());
        }, self::UNIQUE_INDEXES);

        return ApiResponse::item(new EmployeeResource($employee->refresh()->load(['branch', 'costCenter', 'user'])), __('api.created'), 201);
    }

    #[OA\Get(
        path: '/employees/{employee}',
        operationId: 'employeesShow',
        summary: 'Detalha colaborador',
        description: 'Permissão: employees.view. Excluído ou de outra filial (L) → 404.',
        tags: ['Colaboradores'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'employee', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Colaborador.', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function show(Employee $employee): JsonResponse
    {
        Gate::authorize('view', $employee);

        return ApiResponse::item(new EmployeeResource($employee->load(['branch', 'costCenter', 'user'])));
    }

    #[OA\Put(
        path: '/employees/{employee}',
        operationId: 'employeesUpdate',
        summary: 'Atualiza colaborador (parcial; PUT = PATCH)',
        description: 'Permissão: employees.manage. Só os campos enviados são validados e gravados; as regras de filial valem também ao trocar a filial do colaborador.',
        tags: ['Colaboradores'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'employee', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EmployeeInput')),
        responses: [
            new OA\Response(response: 200, description: 'Atualizado.', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    #[OA\Patch(
        path: '/employees/{employee}',
        operationId: 'employeesPatch',
        summary: 'Atualiza colaborador (parcial; igual ao PUT)',
        tags: ['Colaboradores'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'employee', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EmployeeInput')),
        responses: [
            new OA\Response(response: 200, description: 'Atualizado.', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function update(EmployeeRequest $request, Employee $employee): JsonResponse
    {
        Gate::authorize('update', $employee);
        $this->persist(function () use ($request, $employee) {
            $this->lockRelated($request->validated(), $employee);
            $employee->update($request->validated());
        }, self::UNIQUE_INDEXES);

        return ApiResponse::item(new EmployeeResource($employee->refresh()->load(['branch', 'costCenter', 'user'])), __('api.updated'));
    }

    #[OA\Delete(
        path: '/employees/{employee}',
        operationId: 'employeesDestroy',
        summary: 'Exclui colaborador (soft delete)',
        description: 'Permissão: employees.manage. 409 (`errors.dependents=["equipments"]`) se for responsável por equipamento ativo, regra ativada na F1-15. O usuário vinculado fica livre.',
        tags: ['Colaboradores'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'employee', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Excluído (`data` null).', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, description: 'Dependentes ativos.', content: new OA\JsonContent(ref: '#/components/schemas/DeleteConflictEnvelope')),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function destroy(Employee $employee): JsonResponse
    {
        Gate::authorize('delete', $employee);
        $this->softDeleteGuarded($employee);

        return ApiResponse::success(null, __('api.deleted'));
    }

    #[OA\Post(
        path: '/employees/{employee}/restore',
        operationId: 'employeesRestore',
        summary: 'Restaura colaborador excluído',
        description: 'Permissão: employees.manage. 409 se não estiver excluído, se a matrícula foi reutilizada, se o usuário vinculado está excluído, já vinculado a outro colaborador ou (não-admin) em outra filial, ou se a filial ou o centro de custo vinculado está excluído (ou o centro de custo mudou para outra filial).',
        tags: ['Colaboradores'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'employee', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Restaurado.', content: new OA\JsonContent(ref: '#/components/schemas/EmployeeEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/Conflict'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function restore(Employee $employee): JsonResponse
    {
        Gate::authorize('restore', $employee);
        $restored = $this->restoreGuarded($employee, fn (Model $locked) => $locked instanceof Employee ? $this->restoreConflict($locked) : null, 'registration');

        return ApiResponse::item(new EmployeeResource($restored->load(['branch', 'costCenter', 'user'])), __('api.restored'));
    }

    /**
     * Travas e consistências de filial (D.2 v1.5), na transação da gravação. Filial, centro de custo e
     * usuário vinculado são travados com FOR SHARE: a exclusão deles (FOR UPDATE) e as alterações de
     * filial do centro de custo/usuário esperam esta transação. Só checa o que o payload muda.
     *
     * @param  array<string, mixed>  $data
     */
    private function lockRelated(array $data, ?Employee $employee): void
    {
        $branchChanged = array_key_exists('branch_id', $data);
        $branchId = $branchChanged ? $data['branch_id'] : $employee?->branch_id;
        if ($branchChanged) {
            $this->lockBranch($branchId);
        }

        $costCenterId = array_key_exists('cost_center_id', $data) ? $data['cost_center_id'] : $employee?->cost_center_id;
        if ($costCenterId !== null && ($branchChanged || array_key_exists('cost_center_id', $data))) {
            $costCenter = CostCenter::query()->withoutGlobalScope(BranchScope::class)->whereKey($costCenterId)->sharedLock()->first(['id', 'branch_id']);
            if ($costCenter === null) {
                throw ValidationException::withMessages(['cost_center_id' => FieldMessage::for('exists', 'cost_center_id')]);
            }
            if ($costCenter->branch_id !== null && (int) $costCenter->branch_id !== (int) $branchId) {
                throw ValidationException::withMessages(['cost_center_id' => __('api.employee_cost_center_branch_mismatch')]);
            }
        }

        $userId = array_key_exists('user_id', $data) ? $data['user_id'] : $employee?->user_id;
        if ($userId !== null && ($branchChanged || array_key_exists('user_id', $data))) {
            $user = User::query()->whereKey($userId)->sharedLock()->first(['id', 'role', 'branch_id']);
            if ($user === null) {
                throw ValidationException::withMessages(['user_id' => FieldMessage::for('exists', 'user_id')]);
            }
            if (! $user->isAdmin() && (int) $user->branch_id !== (int) $branchId) {
                $field = array_key_exists('user_id', $data) ? 'user_id' : 'branch_id';
                throw ValidationException::withMessages([$field => __('api.employee_user_branch_mismatch')]);
            }
        }
    }

    /** Regras do restore (409) além da matrícula reutilizada, com as linhas relacionadas travadas. */
    private function restoreConflict(Employee $employee): ?string
    {
        if (Branch::query()->whereKey($employee->branch_id)->sharedLock()->first(['id']) === null) {
            return __('api.restore_branch_deleted');
        }
        if ($employee->cost_center_id !== null) {
            $costCenter = CostCenter::query()->withoutGlobalScope(BranchScope::class)->whereKey($employee->cost_center_id)->sharedLock()->first(['id', 'branch_id']);
            if ($costCenter === null) {
                return __('api.restore_cost_center_deleted');
            }
            if ($costCenter->branch_id !== null && (int) $costCenter->branch_id !== (int) $employee->branch_id) {
                return __('api.employee_cost_center_branch_mismatch');
            }
        }
        if ($employee->user_id !== null) {
            $user = User::query()->whereKey($employee->user_id)->sharedLock()->first(['id', 'role', 'branch_id']);
            $linked = Employee::query()->withoutGlobalScope(BranchScope::class)->where('user_id', $employee->user_id)->whereKeyNot($employee->getKey())->exists();
            if ($user === null || $linked) {
                return __('api.restore_user_unavailable');
            }
            if (! $user->isAdmin() && (int) $user->branch_id !== (int) $employee->branch_id) {
                return __('api.employee_user_branch_mismatch');
            }
        }

        return null;
    }
}
