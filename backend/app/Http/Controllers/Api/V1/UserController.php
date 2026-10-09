<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Exceptions\DomainConflictException;
use App\Http\Controllers\Concerns\CrudActions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\UserRequest;
use App\Http\Resources\ManagedUserResource;
use App\Models\Branch;
use App\Models\User;
use App\Support\Api\ApiResponse;
use App\Support\Api\ListQuery;
use App\Support\Audit\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UserInput',
    description: 'Create: name, username, email, password e role obrigatórios. Update (PUT = PATCH): parcial. `username`: trim + minúsculas e depois `^[a-z0-9]{3,30}$` (acento, espaço, ponto, `_`, `-` ou `@` → 422, sem transliteração), único entre não excluídos. `email` é gravado em minúsculas e é único entre usuários não excluídos. `password`: mín. 10 caracteres, com maiúscula, minúscula e número. `branch_id` é obrigatório quando o perfil (enviado ou atual) não é admin, inclusive ao trocar de admin para outro perfil; filial existente e não excluída (inativa é aceita).',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'Ana Souza'),
        new OA\Property(property: 'username', type: 'string', pattern: '^[a-z0-9]{3,30}$', minLength: 3, maxLength: 30, example: 'anasouza'),
        new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 190, example: 'ana@example.com'),
        new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 10, maxLength: 255, writeOnly: true),
        new OA\Property(property: 'role', type: 'string', enum: ['operator', 'mechanic', 'leader', 'admin']),
        new OA\Property(property: 'branch_id', type: 'integer', nullable: true, example: 1),
        new OA\Property(property: 'is_active', type: 'boolean', default: true),
    ],
)]
#[OA\Schema(
    schema: 'UserEnvelope',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/Envelope'),
        new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/User')]),
    ],
)]
class UserController extends Controller
{
    use CrudActions;

    /** Índices únicos parciais → campo do 422 quando a corrida passa pela validação. */
    private const UNIQUE_INDEXES = ['users_email_unique' => 'email', 'users_username_unique' => 'username'];

    public function __construct(private readonly AuditService $audit) {}

    #[OA\Get(
        path: '/users',
        operationId: 'usersIndex',
        summary: 'Lista usuários',
        description: 'Permissão: users.view (A). Sem escopo de filial. Busca `q` em name, username e email. Ordenação: name, username, email, role, created_at, last_login_at (`-` = desc; padrão name).',
        tags: ['Usuários'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/Page'),
            new OA\Parameter(ref: '#/components/parameters/PerPage'),
            new OA\Parameter(ref: '#/components/parameters/Search'),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', example: '-last_login_at,name')),
            new OA\Parameter(name: 'role', in: 'query', schema: new OA\Schema(type: 'string', enum: ['operator', 'mechanic', 'leader', 'admin'])),
            new OA\Parameter(name: 'branch_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(ref: '#/components/parameters/IsActive'),
            new OA\Parameter(ref: '#/components/parameters/WithTrashed'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista paginada.', content: new OA\JsonContent(allOf: [
                new OA\Schema(ref: '#/components/schemas/Envelope'),
                new OA\Schema(required: ['meta'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/User'))]),
            ])),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        // User nunca usa BranchScoped (alerta da F1-10): o filtro por filial é o parâmetro explícito.
        $paginator = ListQuery::for($request, User::query()->with('branch'))
            ->search(['name', 'username', 'email'])
            ->filters(['role' => ['string', Rule::enum(Role::class)], 'branch_id' => ['integer'], 'is_active' => ['boolean']])
            ->sortable(['name', 'username', 'email', 'role', 'created_at', 'last_login_at'], 'name')
            ->paginate();

        return ApiResponse::paginated($paginator, ManagedUserResource::class);
    }

    #[OA\Post(
        path: '/users',
        operationId: 'usersStore',
        summary: 'Cria usuário',
        description: 'Permissão: users.manage. E-mail duplicado entre usuários ativos → 422; perfil não-admin sem `branch_id` → 422; filial inexistente ou excluída → 422.',
        tags: ['Usuários'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UserInput')),
        responses: [
            new OA\Response(response: 201, description: 'Criado.', content: new OA\JsonContent(ref: '#/components/schemas/UserEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function store(UserRequest $request): JsonResponse
    {
        $user = $this->persist(function () use ($request) {
            $this->lockBranch($request->validated('branch_id'));

            return User::query()->create($request->validated());
        }, self::UNIQUE_INDEXES);

        return ApiResponse::item(new ManagedUserResource($user->refresh()->load('branch')), __('api.created'), 201);
    }

    #[OA\Get(
        path: '/users/{user}',
        operationId: 'usersShow',
        summary: 'Detalha usuário',
        description: 'Permissão: users.view. Usuário excluído → 404.',
        tags: ['Usuários'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Usuário.', content: new OA\JsonContent(ref: '#/components/schemas/UserEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function show(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return ApiResponse::item(new ManagedUserResource($user->load('branch')));
    }

    #[OA\Put(
        path: '/users/{user}',
        operationId: 'usersUpdate',
        summary: 'Atualiza usuário (parcial; PUT = PATCH)',
        description: 'Permissão: users.manage. Só os campos enviados são validados e gravados. Usuário vinculado a colaborador: não-admin precisa ficar na filial do colaborador (422 ao mudar branch_id ou rebaixar de admin). Revoga todos os tokens do usuário ao trocar a senha de outro usuário ou ao desativá-lo (na própria senha, revoga os demais tokens). 409: alterar o próprio perfil, desativar a si mesmo, rebaixar ou desativar o último admin ativo.',
        tags: ['Usuários'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UserInput')),
        responses: [
            new OA\Response(response: 200, description: 'Atualizado.', content: new OA\JsonContent(ref: '#/components/schemas/UserEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/Conflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    #[OA\Patch(
        path: '/users/{user}',
        operationId: 'usersPatch',
        summary: 'Atualiza usuário (parcial; igual ao PUT)',
        tags: ['Usuários'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UserInput')),
        responses: [
            new OA\Response(response: 200, description: 'Atualizado.', content: new OA\JsonContent(ref: '#/components/schemas/UserEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/Conflict'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function update(UserRequest $request, User $user): JsonResponse
    {
        Gate::authorize('update', $user);
        $data = $request->validated();
        /** @var User $actor */
        $actor = $request->user();
        $self = $actor->is($user);

        $newRole = array_key_exists('role', $data) ? Role::from($data['role']) : $user->role;
        $deactivates = array_key_exists('is_active', $data) && ! filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN) && $user->is_active;
        if ($self && $newRole !== $user->role) {
            throw new DomainConflictException(__('api.user_self_role'), ['role' => [__('api.user_self_role')]]);
        }
        if ($self && $deactivates) {
            throw new DomainConflictException(__('api.user_self_deactivate'), ['is_active' => [__('api.user_self_deactivate')]]);
        }
        $passwordChanged = array_key_exists('password', $data);

        $this->persist(function () use ($user, $data, $self, $newRole, $deactivates, $passwordChanged, $actor) {
            if ($user->role === Role::Admin && ($newRole !== Role::Admin || $deactivates)) {
                $this->guardLastAdmin($user);
            }
            if (array_key_exists('branch_id', $data)) {
                $this->lockBranch($data['branch_id']);
            }
            $this->guardEmployeeBranch($user, $data, $newRole);
            $user->update($data);

            if ($deactivates || ($passwordChanged && ! $self)) {
                $revoked = $user->tokens()->delete();
            } elseif ($passwordChanged) {
                $current = $actor->currentAccessToken();
                $revoked = $user->tokens()->when($current instanceof PersonalAccessToken, fn ($q) => $q->whereKeyNot($current->getKey()))->delete();
            }
            if ($passwordChanged) {
                // A senha nunca entra no audit (updated ignora password): registra o evento com a revogação.
                $this->audit->record(AuditAction::PasswordChanged, $user, null, null, ['revoked_tokens' => $revoked ?? 0]);
            }
        }, self::UNIQUE_INDEXES);

        return ApiResponse::item(new ManagedUserResource($user->refresh()->load('branch')), __('api.updated'));
    }

    #[OA\Delete(
        path: '/users/{user}',
        operationId: 'usersDestroy',
        summary: 'Exclui usuário (soft delete)',
        description: 'Permissão: users.manage. Revoga todos os tokens do usuário. 409: excluir a si mesmo, o último admin ativo ou usuário com colaborador vinculado não excluído (`errors.dependents=["employees"]`).',
        tags: ['Usuários'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Excluído (`data` null).', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/Conflict'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function destroy(Request $request, User $user): JsonResponse
    {
        Gate::authorize('delete', $user);
        if ($request->user()?->is($user)) {
            throw new DomainConflictException(__('api.user_self_delete'));
        }

        $this->softDeleteGuarded(
            $user,
            before: function () use ($user) {
                if ($user->role === Role::Admin && $user->is_active) {
                    $this->guardLastAdmin($user);
                }
            },
            after: fn (Model $locked) => $locked instanceof User ? $locked->tokens()->delete() : null,
        );

        return ApiResponse::success(null, __('api.deleted'));
    }

    #[OA\Post(
        path: '/users/{user}/restore',
        operationId: 'usersRestore',
        summary: 'Restaura usuário excluído',
        description: 'Permissão: users.manage. 409 se não estiver excluído, se o e-mail ou o username já estiver em uso por um usuário ativo ou se a filial vinculada estiver excluída. Os tokens revogados na exclusão não voltam.',
        tags: ['Usuários'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Restaurado.', content: new OA\JsonContent(ref: '#/components/schemas/UserEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/Conflict'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function restore(User $user): JsonResponse
    {
        Gate::authorize('restore', $user);
        $restored = $this->restoreGuarded($user, function (Model $locked) {
            $branchId = $locked->getAttribute('branch_id');

            return $branchId !== null && Branch::query()->whereKey($branchId)->sharedLock()->first(['id']) === null
                ? __('api.restore_branch_deleted')
                : null;
        }, ['email', 'username']);

        return ApiResponse::item(new ManagedUserResource($restored->load('branch')), __('api.restored'));
    }

    /**
     * Colaborador vinculado (D.2 v1.5): usuário não-admin precisa ficar na filial do colaborador, ao mudar
     * o branch_id ou ao rebaixar de admin (422). A linha do usuário é travada antes da checagem: quem
     * grava colaborador trava o usuário com FOR SHARE.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardEmployeeBranch(User $user, array $data, Role $newRole): void
    {
        if ($newRole === Role::Admin || (! array_key_exists('branch_id', $data) && $newRole === $user->role)) {
            return;
        }
        User::query()->whereKey($user->getKey())->lockForUpdate()->first(['id']);
        $employee = $user->employee()->first(['id', 'branch_id']);
        $branchId = array_key_exists('branch_id', $data) ? $data['branch_id'] : $user->branch_id;

        if ($employee !== null && (int) $employee->branch_id !== (int) $branchId) {
            throw ValidationException::withMessages(['branch_id' => __('api.user_employee_branch_mismatch')]);
        }
    }

    /**
     * Último admin ativo (409): trava (FOR UPDATE, em ordem de id) as linhas de admin ativo não excluído
     * e falha se $user for o único. Roda antes de travar/alterar o próprio usuário, para que duas
     * operações concorrentes sobre admins diferentes travem na mesma ordem (sem deadlock) e a segunda
     * enxergue o resultado da primeira.
     */
    private function guardLastAdmin(User $user): void
    {
        $ids = User::query()->where('role', Role::Admin->value)->where('is_active', true)
            ->orderBy('id')->lockForUpdate()->pluck('id');

        if ($ids->count() <= 1 && $ids->contains($user->getKey())) {
            throw new DomainConflictException(__('api.user_last_admin'));
        }
    }
}
