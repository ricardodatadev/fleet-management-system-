<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\Api\ApiResponse;
use App\Support\Api\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AuditLogEnvelope',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/Envelope'),
        new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AuditLog')]),
    ],
)]
class AuditLogController extends Controller
{
    #[OA\Get(
        path: '/audit-logs',
        operationId: 'auditLogsIndex',
        summary: 'Lista o audit trail (somente leitura)',
        description: 'Permissão: audit.view (A). Ordenação fixa: event_at desc, id desc (`sort` é ignorado). `from`/`to` em ISO 8601 sobre event_at, inclusivos; data sem hora = dia inteiro em UTC (`from` às 00:00, `to` até 23:59:59.999999); `from` > `to` → 422. Não há escrita: POST/PUT/PATCH/DELETE → 405.',
        tags: ['Auditoria'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/Page'),
            new OA\Parameter(ref: '#/components/parameters/PerPage'),
            new OA\Parameter(name: 'auditable_type', in: 'query', description: 'Alias curto do registro auditado; desconhecido → 422.', schema: new OA\Schema(type: 'string', enum: ['branch', 'cost_center', 'employee', 'equipment_family', 'user'])),
            new OA\Parameter(name: 'auditable_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'actor_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'action', in: 'query', schema: new OA\Schema(type: 'string', enum: ['created', 'updated', 'deleted', 'restored', 'login_succeeded', 'login_failed', 'logout', 'password_changed', 'setting_changed', 'setting_removed'])),
            new OA\Parameter(name: 'from', in: 'query', description: 'ISO 8601 (date-time ou só a data, dia inteiro em UTC); inclusivo.', schema: new OA\Schema(type: 'string', example: '2026-10-01T00:00:00Z')),
            new OA\Parameter(name: 'to', in: 'query', description: 'ISO 8601 (date-time ou só a data, até 23:59:59.999999 UTC); inclusivo; menor que `from` → 422.', schema: new OA\Schema(type: 'string', example: '2026-10-31')),
            new OA\Parameter(name: 'request_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista paginada.', content: new OA\JsonContent(allOf: [
                new OA\Schema(ref: '#/components/schemas/Envelope'),
                new OA\Schema(required: ['meta'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AuditLog'))]),
            ])),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 422, ref: '#/components/responses/ValidationError'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        $paginator = ListQuery::for($request, AuditLog::query())
            ->filters(
                [
                    'auditable_type' => ['string', Rule::in(array_keys(AuditLog::AUDITABLE_TYPES))],
                    'auditable_id' => ['integer'],
                    'actor_id' => ['integer'],
                    'action' => ['string', Rule::enum(AuditAction::class)],
                    'from' => ['date'],
                    'to' => ['date'],
                    'request_id' => ['uuid'],
                ],
                [
                    'auditable_type' => function (Builder $q, string $alias): void {
                        $q->where('auditable_type', AuditLog::morphClassFor($alias));
                    },
                    // string com microssegundos: o Carbon direto seria formatado sem a fração pelo grammar
                    'from' => function (Builder $q) use ($from): void {
                        $q->where('event_at', '>=', $from?->format('Y-m-d H:i:s.uP'));
                    },
                    'to' => function (Builder $q) use ($to): void {
                        $q->where('event_at', '<=', $to?->format('Y-m-d H:i:s.uP'));
                    },
                ],
            )
            ->fixedOrder([['event_at', 'desc'], ['id', 'desc']])
            ->paginate();

        return ApiResponse::paginated($paginator, AuditLogResource::class);
    }

    #[OA\Get(
        path: '/audit-logs/{audit_log}',
        operationId: 'auditLogsShow',
        summary: 'Detalha um evento do audit trail',
        description: 'Permissão: audit.view (A). Inexistente → 404.',
        tags: ['Auditoria'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'audit_log', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Evento.', content: new OA\JsonContent(ref: '#/components/schemas/AuditLogEnvelope')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 429, ref: '#/components/responses/TooManyRequests'),
        ],
    )]
    public function show(AuditLog $auditLog): JsonResponse
    {
        Gate::authorize('view', $auditLog);

        return ApiResponse::item(new AuditLogResource($auditLog));
    }

    /**
     * Limites do período (UTC, inclusivos). Data sem hora cobre o dia inteiro. `from` > `to` → 422.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function period(Request $request): array
    {
        $input = Validator::make($request->query(), [
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ])->validate();

        $parse = function (?string $value, bool $end): ?CarbonImmutable {
            if ($value === null || $value === '') {
                return null;
            }
            $dateOnly = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
            $at = $dateOnly ? CarbonImmutable::parse($value, 'UTC') : CarbonImmutable::parse($value)->utc();

            return $dateOnly ? ($end ? $at->endOfDay() : $at->startOfDay()) : $at;
        };
        $from = $parse($input['from'] ?? null, false);
        $to = $parse($input['to'] ?? null, true);

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            throw ValidationException::withMessages(['to' => __('validation.after_or_equal', ['attribute' => 'to', 'date' => 'from'])]);
        }

        return [$from, $to];
    }
}
