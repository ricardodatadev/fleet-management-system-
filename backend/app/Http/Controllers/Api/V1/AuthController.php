<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use App\Support\Audit\AuditService;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
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
        description: 'Público. Só username + senha (v1.8); o e-mail serve apenas para a recuperação de senha. `username` passa por trim + minúsculas, sem validação de formato. Username inexistente (inclusive um e-mail digitado), usuário excluído ou senha errada → 422 em `errors.username` com a mesma mensagem genérica. Campo vazio → 422 por campo. Usuário inativo → 403 (somente com a senha correta). Rate limit: 5/min por username normalizado + IP e 20/min por IP (toda tentativa conta). Token expira em SANCTUM_EXPIRATION minutos (padrão 720).',
        tags: ['Auth'],
        security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['username', 'password', 'device_name'],
            properties: [
                new OA\Property(property: 'username', description: 'Nome de usuário (trim + minúsculas no servidor).', type: 'string', maxLength: 190, example: 'admin'),
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
        $username = $request->username();
        $password = $request->string('password')->toString();
        // Só username (v1.8): um e-mail digitado não acha ninguém. Soft-deleted fica fora da consulta.
        $user = User::query()->where('username', $username)->first();

        // Hash conferido mesmo sem usuário, para o tempo de resposta não revelar se o username existe.
        $valid = Hash::check($password, $user?->password ?? (self::$dummyHash ??= Hash::make(bin2hex(random_bytes(16)))));

        if ($user === null || ! $valid) {
            $this->audit->record(AuditAction::LoginFailed, null, null, null, ['username' => $username, 'reason' => 'invalid_credentials']);

            throw ValidationException::withMessages(['username' => __('auth.failed')]);
        }

        if (! $user->is_active) {
            $this->audit->record(AuditAction::LoginFailed, null, null, null, ['username' => $username, 'reason' => 'inactive']);

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

    #[OA\Post(
        path: '/auth/forgot-password',
        operationId: 'authForgotPassword',
        summary: 'Pede o link de redefinição de senha',
        description: 'Público. Sempre 200 com a mesma mensagem, exista ou não o e-mail (sem enumeração). Só usuário ativo e não excluído recebe o e-mail, enviado pela fila; o link é `${APP_FRONTEND_URL}/redefinir-senha#token=…&email=…` (fragmento: não vai ao servidor), vale 60 min e um pedido novo invalida o anterior. E-mail vazio ou malformado → 422. Rate limit: 3/min por e-mail + IP e 10/min por IP.',
        tags: ['Auth'],
        security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email'],
            properties: [new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 190, example: 'ana@example.com')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Mensagem genérica (`data` null).', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
            new OA\Response(response: 500, ref: '#/components/responses/ServerError'),
        ],
    )]
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $broker = $this->broker();
        // Soft-deleted fica fora da consulta; inativo não recebe (e a resposta é a mesma).
        $user = User::query()->where('email', $request->email())->where('is_active', true)->first();

        if ($user !== null) {
            DB::transaction(function () use ($user, $broker) {
                // createToken substitui o token anterior do e-mail (uso único, 60 min).
                $user->sendPasswordResetNotification($broker->createToken($user));
                $this->audit->record(AuditAction::PasswordResetRequested, $user);
            });
        }

        return ApiResponse::success(null, __('auth.reset_link_sent'));
    }

    #[OA\Post(
        path: '/auth/reset-password',
        operationId: 'authResetPassword',
        summary: 'Redefine a senha com o link recebido por e-mail',
        description: 'Público. Política de senha → 422 por campo (`password`, inclusive a confirmação). Link inválido, expirado, já usado, de outro e-mail ou de usuário inativo/excluído → 422 genérico em `errors.token`. Sucesso revoga todos os tokens de acesso do usuário e não faz login automático. Rate limit: 5/min por IP.',
        tags: ['Auth'],
        security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'token', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 190),
                new OA\Property(property: 'token', description: 'Token do link do e-mail.', type: 'string', maxLength: 255),
                new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 10, maxLength: 255),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Senha redefinida (`data` null).', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
            new OA\Response(response: 500, ref: '#/components/responses/ServerError'),
        ],
    )]
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $broker = $this->broker();
        $invalid = fn () => ValidationException::withMessages(['token' => __('auth.reset_invalid')]);
        $email = $request->email();

        DB::transaction(function () use ($request, $broker, $email, $invalid) {
            // A linha do usuário é travada: dois resets com o mesmo token não passam juntos (uso único).
            $user = User::query()->where('email', $email)->where('is_active', true)->lockForUpdate()->first();
            if ($user === null || ! $broker->tokenExists($user, $request->string('token')->toString())) {
                throw $invalid();
            }

            Auth::setUser($user); // ainda não há usuário autenticado: ator do password_reset
            $user->password = $request->string('password')->toString();
            $user->save();
            $broker->deleteToken($user);
            $revoked = $user->tokens()->delete();

            $this->audit->record(AuditAction::PasswordReset, $user, null, null, ['revoked_tokens' => $revoked]);
        });

        return ApiResponse::success(null, __('auth.password_reset'));
    }

    /** Broker de senhas do Laravel (tabela password_reset_tokens, expiração em config/auth.php). */
    private function broker(): PasswordBroker
    {
        $broker = Password::broker();
        assert($broker instanceof PasswordBroker);

        return $broker;
    }

    private function currentToken(User $user): PersonalAccessToken
    {
        $token = $user->currentAccessToken();
        assert($token instanceof PersonalAccessToken);

        return $token;
    }
}
