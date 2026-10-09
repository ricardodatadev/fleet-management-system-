<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SettingRequest;
use App\Http\Resources\SettingResource;
use App\Models\Setting;
use App\Models\User;
use App\Support\Api\ApiResponse;
use App\Support\Api\FieldMessage;
use App\Support\Api\ListQuery;
use App\Support\Settings\SettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SettingDefinition',
    required: ['key', 'rule', 'label', 'description', 'type', 'values', 'default', 'scopes', 'placeholder'],
    properties: [
        new OA\Property(property: 'key', type: 'string', example: 'warranty.alert_mode'),
        new OA\Property(property: 'rule', description: 'Regra de negócio de origem.', type: 'string', example: 'RN-004'),
        new OA\Property(property: 'label', type: 'string', example: 'Tratamento de peça ou serviço em garantia'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'type', type: 'string', enum: ['enum', 'bool']),
        new OA\Property(
            property: 'values',
            description: 'Valores permitidos (só enum); null em bool.',
            type: 'array',
            nullable: true,
            items: new OA\Items(required: ['value', 'label'], properties: [
                new OA\Property(property: 'value', type: 'string', example: 'warning'),
                new OA\Property(property: 'label', type: 'string', example: 'Apenas alertar'),
            ]),
        ),
        new OA\Property(property: 'default', description: 'Valor padrão (string do enum ou boolean).', example: 'warning'),
        new OA\Property(property: 'scopes', type: 'array', items: new OA\Items(type: 'string', enum: Setting::SCOPES)),
        new OA\Property(property: 'placeholder', description: 'Chave provisória (D14: RN-002).', type: 'boolean'),
    ],
)]
#[OA\Schema(
    schema: 'SettingEffective',
    required: ['key', 'value', 'source'],
    properties: [
        new OA\Property(property: 'key', type: 'string', example: 'warranty.alert_mode'),
        new OA\Property(property: 'value', description: 'Valor efetivo (string do enum ou boolean).', example: 'hard_block'),
        new OA\Property(
            property: 'source',
            description: 'Override que definiu o valor `{scope_type, scope_id}` ou "default" (registry).',
            oneOf: [
                new OA\Schema(type: 'string', enum: ['default']),
                new OA\Schema(type: 'object', required: ['scope_type', 'scope_id'], properties: [
                    new OA\Property(property: 'scope_type', type: 'string', enum: Setting::SCOPES),
                    new OA\Property(property: 'scope_id', type: 'integer', nullable: true),
                ]),
            ],
        ),
    ],
)]
class SettingController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    #[OA\Get(
        path: '/settings/definitions',
        operationId: 'settingsDefinitions',
        summary: 'Registry dos parâmetros (RN-001..004)',
        description: 'Permissão: settings.view (L e A). Chave, regra de origem, rótulo, tipo, valores permitidos, default e escopos permitidos.',
        tags: ['Parâmetros'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Definições.', content: new OA\JsonContent(allOf: [
                new OA\Schema(ref: '#/components/schemas/Envelope'),
                new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SettingDefinition'))]),
            ])),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function definitions(): JsonResponse
    {
        $data = [];
        foreach (SettingsService::registry() as $key => $definition) {
            $data[] = [
                'key' => $key,
                'rule' => $definition['rule'],
                'label' => $definition['label'],
                'description' => $definition['description'],
                'type' => $definition['type'],
                'values' => $definition['values'] === null ? null
                    : array_map(fn ($value, $label) => ['value' => $value, 'label' => $label], array_keys($definition['values']), $definition['values']),
                'default' => $definition['default'],
                'scopes' => $definition['scopes'],
                'placeholder' => $definition['placeholder'],
            ];
        }

        return ApiResponse::success($data);
    }

    #[OA\Get(
        path: '/settings',
        operationId: 'settingsIndex',
        summary: 'Lista os overrides gravados',
        description: 'Permissão: settings.view (L e A). Filtros `key`, `scope_type` e `scope_id` (este exige `scope_type`). O líder vê os globais, os de família e os da própria filial; `scope_type=branch` com `scope_id` de outra filial → 403. Ordem: key, scope_type, scope_id.',
        tags: ['Parâmetros'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/Page'),
            new OA\Parameter(ref: '#/components/parameters/PerPage'),
            new OA\Parameter(name: 'key', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'scope_type', in: 'query', schema: new OA\Schema(type: 'string', enum: Setting::SCOPES)),
            new OA\Parameter(name: 'scope_id', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista paginada.', content: new OA\JsonContent(allOf: [
                new OA\Schema(ref: '#/components/schemas/Envelope'),
                new OA\Schema(required: ['meta'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Setting'))]),
            ])),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $input = Validator::make($request->query(), [
            'scope_id' => ['sometimes', 'nullable', 'integer'],
            // scope_id sozinho é ambíguo (filial ou família): exige o scope_type
            'scope_type' => [Rule::requiredIf($request->filled('scope_id')), 'nullable', 'string', Rule::in(Setting::SCOPES)],
        ])->validate();
        if (($input['scope_type'] ?? null) === 'branch' && isset($input['scope_id'])) {
            $this->forbidOtherBranch($request, (int) $input['scope_id']);
        }

        $query = Setting::query()->with('updatedBy');
        $user = $this->user($request);
        if (! $user->isAdmin()) {
            // líder: globais, famílias e a própria filial
            $query->where(fn (Builder $q) => $q->where('scope_type', '!=', 'branch')->orWhere('scope_id', $user->branch_id));
        }

        $paginator = ListQuery::for($request, $query)
            ->filters([
                'key' => ['string', Rule::in(array_keys(SettingsService::registry()))],
                'scope_type' => ['string', Rule::in(Setting::SCOPES)],
                'scope_id' => ['integer'],
            ])
            ->fixedOrder([['key', 'asc'], ['scope_type', 'asc'], ['scope_id', 'asc'], ['id', 'asc']])
            ->paginate();
        SettingResource::preloadScopes($paginator->getCollection());

        return ApiResponse::paginated($paginator, SettingResource::class);
    }

    #[OA\Get(
        path: '/settings/effective',
        operationId: 'settingsEffective',
        summary: 'Valor efetivo de um parâmetro e a sua origem',
        description: 'Permissão: settings.view (L e A). Precedência family > branch > global > default do registry, só entre os escopos permitidos da chave. `branch_id`/`family_id` existentes e não excluídos (422). O líder só consulta a própria filial (outra → 403); famílias são livres.',
        tags: ['Parâmetros'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'key', in: 'query', required: true, schema: new OA\Schema(type: 'string', example: 'warranty.alert_mode')),
            new OA\Parameter(name: 'branch_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'family_id', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Valor efetivo.', content: new OA\JsonContent(allOf: [
                new OA\Schema(ref: '#/components/schemas/Envelope'),
                new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SettingEffective')]),
            ])),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function effective(Request $request): JsonResponse
    {
        $input = Validator::make($request->all(), [
            'key' => ['required', 'string', Rule::in(array_keys(SettingsService::registry()))],
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'family_id' => ['sometimes', 'nullable', 'integer', Rule::exists('equipment_families', 'id')->whereNull('deleted_at')],
        ], ['key.in' => __('api.setting_unknown_key', ['key' => (string) $request->input('key')])])->validate();

        $branchId = isset($input['branch_id']) ? (int) $input['branch_id'] : null;
        if ($branchId !== null) {
            $this->forbidOtherBranch($request, $branchId);
        }
        $familyId = isset($input['family_id']) ? (int) $input['family_id'] : null;

        return ApiResponse::success($this->settings->resolve($input['key'], $branchId, $familyId));
    }

    #[OA\Put(
        path: '/settings/{key}',
        operationId: 'settingsUpdate',
        summary: 'Grava o override de um parâmetro (upsert idempotente)',
        description: 'Permissão: settings.manage (A). Valida contra o registry (422): chave inexistente, escopo não permitido para a chave, `scope_id` ausente/inexistente/excluído (ou presente no global) e valor fora do tipo (enum: um dos valores; bool: true/false). O mesmo valor de novo não muda nada nem gera auditoria; mudança → `setting_changed` com old/new e escopo.',
        tags: ['Parâmetros'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'warranty.alert_mode'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['scope_type', 'scope_id', 'value'],
            properties: [
                new OA\Property(property: 'scope_type', type: 'string', enum: Setting::SCOPES),
                new OA\Property(property: 'scope_id', description: 'null no global; id da filial ou da família.', type: 'integer', nullable: true),
                new OA\Property(property: 'value', description: 'String do enum ou boolean, conforme a chave.', example: 'hard_block'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Gravado.', content: new OA\JsonContent(allOf: [
                new OA\Schema(ref: '#/components/schemas/Envelope'),
                new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Setting')]),
            ])),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function update(SettingRequest $request): JsonResponse
    {
        $scopeId = $request->input('scope_id');
        $setting = $this->settings->put(
            $request->settingKey(),
            (string) $request->input('scope_type'),
            $scopeId === null ? null : (int) $scopeId,
            $request->input('value'),
        );

        // mesmo valor de antes: nada mudou nem foi auditado, e a mensagem diz isso
        $changed = $setting->wasRecentlyCreated || $setting->wasChanged('value');

        return ApiResponse::item(new SettingResource($setting->load('updatedBy')), __($changed ? 'api.setting_saved' : 'api.setting_unchanged'));
    }

    #[OA\Delete(
        path: '/settings/{key}',
        operationId: 'settingsDestroy',
        summary: 'Remove o override de um parâmetro',
        description: 'Permissão: settings.manage (A). `scope_type` (branch ou family) e `scope_id` na query. O global não pode ser removido (422). Override inexistente → 404. Remoção → `setting_removed` com o valor anterior e o escopo.',
        tags: ['Parâmetros'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'warranty.alert_mode')),
            new OA\Parameter(name: 'scope_type', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: Setting::SCOPES)),
            new OA\Parameter(name: 'scope_id', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Removido (`data` null).', content: new OA\JsonContent(ref: '#/components/schemas/Envelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function destroy(Request $request, string $key): JsonResponse
    {
        $input = Validator::make([...$request->all(), 'key' => $key], [
            'key' => ['required', 'string', Rule::in(array_keys(SettingsService::registry()))],
            'scope_type' => ['required', 'string', Rule::in(Setting::SCOPES)],
            'scope_id' => ['nullable', 'integer'],
        ], ['key.in' => __('api.setting_unknown_key', ['key' => $key])])->validate();

        if ($input['scope_type'] === 'global') {
            throw ValidationException::withMessages(['scope_type' => __('api.setting_global_not_removable')]);
        }
        if (($input['scope_id'] ?? null) === null) {
            throw ValidationException::withMessages(['scope_id' => FieldMessage::for('required', 'scope_id')]);
        }
        if (! $this->settings->remove($key, $input['scope_type'], (int) $input['scope_id'])) {
            return ApiResponse::error(__('api.404'), 404);
        }

        return ApiResponse::success(null, __('api.setting_removed'));
    }

    /** Líder (não-admin) só consulta a própria filial (D.2): outra filial → 403. */
    private function forbidOtherBranch(Request $request, int $branchId): void
    {
        $user = $this->user($request);
        if (! $user->isAdmin() && (int) $user->branch_id !== $branchId) {
            throw new AuthorizationException;
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
