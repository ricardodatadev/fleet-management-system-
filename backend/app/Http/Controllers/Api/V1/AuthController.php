<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use App\Support\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LoginData',
    required: ['token', 'token_type', 'expires_at', 'user'],
    properties: [
        new OA\Property(property: 'token', type: 'string', example: '1|9fQk...'),
        new OA\Property(property: 'token_type', type: 'string', enum: ['Bearer']),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', example: '2026-10-09T02:03:22.123456Z'),
        new OA\Property(property: 'user', ref: '#/components/schemas/AuthUser'),
    ],
)]
#[OA\Schema(
    schema: 'MeData',
    required: ['user', 'employee', 'permissions'],
    properties: [
        new OA\Property(property: 'user', ref: '#/components/schemas/AuthUser'),
        new OA\Property(
            property: 'employee',
            description: 'Colaborador vinculado ao usuário (1:1); null quando não há.',
            type: 'object',
            nullable: true,
            required: ['id', 'registration', 'name', 'job_type', 'branch_id'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'registration', type: 'string', example: 'MAT-00123'),
                new OA\Property(property: 'name', type: 'string', example: 'João Pereira'),
                new OA\Property(property: 'job_type', type: 'string', enum: ['driver', 'mechanic', 'leader', 'admin_staff']),
                new OA\Property(property: 'branch_id', type: 'integer', example: 1),
            ],
        ),
        new OA\Property(
            property: 'permissions',
            description: 'Permissões do perfil (config/rbac.php). auth.* é implícito e não aparece.',
            type: 'array',
            items: new OA\Items(type: 'string'),
            example: ['branches.view', 'equipments.view'],
        ),
    ],
)]
class AuthController extends Controller
{
    private static ?string $dummyHash = null;

    public function __construct(private readonly AuditService $audit) {}

    #[OA\Post(
        path: '/auth/login',
        operationId: 'authLogin',
        summary: 'Login (emite token Bearer)',
        description: 'Público. Credenciais inválidas → 422 com mensagem genérica (não revela se o e-mail existe). Usuário inativo → 403 (somente com a senha correta). Rate limit: 5/min por e-mail+IP e 20/min por IP (toda tentativa conta). Token expira em SANCTUM_EXPIRATION minutos (padrão 720).',
        tags: ['Auth'],
        security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'password', 'device_name'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 190, example: 'admin@example.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password', maxLength: 255),
                new OA\Property(property: 'device_name', type: 'string', maxLength: 255, example: 'web'),
            ],
        )),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Autenticado.',
                content: new OA\JsonContent(allOf: [
                    new OA\Schema(ref: '#/components/schemas/Envelope'),
                    new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/LoginData')]),
                ]),
            ),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
            new OA\Response(response: 500, ref: '#/components/responses/ServerError'),
        ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $email = $request->email();
        $password = $request->string('password')->toString();
        // Soft-deleted fica fora da consulta (= e-mail inexistente).
        $user = User::query()->where('email', $email)->first();

        // Hash conferido mesmo sem usuário, para o tempo de resposta não revelar se o e-mail existe.
        $valid = Hash::check($password, $user?->password ?? (self::$dummyHash ??= Hash::make(bin2hex(random_bytes(16)))));

        if ($user === null || ! $valid) {
            $this->audit->record(AuditAction::LoginFailed, null, null, null, ['email' => $email, 'reason' => 'invalid_credentials']);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if (! $user->is_active) {
            $this->audit->record(AuditAction::LoginFailed, null, null, null, ['email' => $email, 'reason' => 'inactive']);

            return ApiResponse::error(__('auth.inactive'), 403);
        }

        $data = DB::transaction(function () use ($user, $request) {
            // Ainda não há usuário autenticado: define o ator do login_succeeded.
            Auth::setUser($user);

            $user->forceFill(['last_login_at' => now()])->save();
            $expiresAt = now()->addMinutes((int) config('sanctum.expiration'));
            $token = $user->createToken($request->string('device_name')->toString(), ['*'], $expiresAt);

            $this->audit->record(AuditAction::LoginSucceeded, $user);

            return [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at->utc()->toJSON(),
                'user' => (new UserResource($user->load('branch')))->resolve($request),
            ];
        });

        return ApiResponse::success($data, __('auth.logged_in'));
    }

    #[OA\Post(
        path: '/auth/logout',
        operationId: 'authLogout',
        summary: 'Logout (revoga o token atual)',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Token revogado (`data` null).', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
            new OA\Response(response: 500, ref: '#/components/responses/ServerError'),
        ],
    )]
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($user) {
            $this->currentToken($user)->delete();
            $this->audit->record(AuditAction::Logout, $user);
        });

        return ApiResponse::success(null, __('auth.logged_out'));
    }

    #[OA\Get(
        path: '/auth/me',
        operationId: 'authMe',
        summary: 'Usuário autenticado, colaborador vinculado e permissões',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dados da sessão.',
                content: new OA\JsonContent(allOf: [
                    new OA\Schema(ref: '#/components/schemas/Envelope'),
                    new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MeData')]),
                ]),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
            new OA\Response(response: 500, ref: '#/components/responses/ServerError'),
        ],
    )]
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success([
            'user' => (new UserResource($user->load('branch')))->resolve($request),
            'employee' => EmployeeResource::summary($user->employee),
            'permissions' => $user->permissions(),
        ]);
    }

    #[OA\Put(
        path: '/auth/password',
        operationId: 'authChangePassword',
        summary: 'Troca a própria senha (revoga os demais tokens)',
        description: 'Política: mín. 10 caracteres, com maiúscula, minúscula e número. O token usado na requisição continua válido; todos os outros do usuário são revogados.',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['current_password', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'current_password', type: 'string', format: 'password'),
                new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 10, maxLength: 255),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Senha alterada (`data` null).', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
            new OA\Response(response: 500, ref: '#/components/responses/ServerError'),
        ],
    )]
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $current = $this->currentToken($user);

        DB::transaction(function () use ($user, $current, $request) {
            $user->password = $request->string('password')->toString();
            $user->save();
            $revoked = $user->tokens()->whereKeyNot($current->getKey())->delete();

            $this->audit->record(AuditAction::PasswordChanged, $user, null, null, ['revoked_tokens' => $revoked]);
        });

        return ApiResponse::success(null, __('auth.password_changed'));
    }

    private function currentToken(User $user): PersonalAccessToken
    {
        $token = $user->currentAccessToken();
        assert($token instanceof PersonalAccessToken);

        return $token;
    }
}
