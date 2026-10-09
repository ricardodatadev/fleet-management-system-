<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Item de /audit-logs no formato da F.1. `actor` vem do snapshot gravado no evento (nome/perfil na
 * época), null em ações do sistema; `auditable.type` é o alias curto (AuditLog::AUDITABLE_TYPES).
 *
 * @mixin AuditLog
 */
#[OA\Schema(
    schema: 'AuditLog',
    required: ['id', 'uuid', 'event_at', 'actor', 'action', 'auditable', 'old_values', 'new_values', 'metadata', 'ip', 'request_id'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'event_at', description: 'UTC com microssegundos.', type: 'string', format: 'date-time', example: '2026-10-08T14:03:22.123456Z'),
        new OA\Property(
            property: 'actor',
            description: 'Quem executou (snapshot da época); null = sistema ou login_failed.',
            type: 'object',
            nullable: true,
            required: ['id', 'name', 'role'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'name', type: 'string', nullable: true, example: 'Ana Souza'),
                new OA\Property(property: 'role', type: 'string', nullable: true, enum: ['operator', 'mechanic', 'leader', 'admin']),
            ],
        ),
        new OA\Property(property: 'action', type: 'string', enum: ['created', 'updated', 'deleted', 'restored', 'login_succeeded', 'login_failed', 'logout', 'password_changed', 'setting_changed', 'setting_removed']),
        new OA\Property(
            property: 'auditable',
            description: 'Registro afetado; null em eventos sem alvo (ex.: login_failed).',
            type: 'object',
            nullable: true,
            required: ['type', 'id'],
            properties: [
                new OA\Property(property: 'type', type: 'string', example: 'cost_center'),
                new OA\Property(property: 'id', type: 'integer', nullable: true, example: 1),
            ],
        ),
        new OA\Property(property: 'old_values', description: 'Valores anteriores (só campos alterados em updated); sem dados sensíveis.', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'new_values', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'metadata', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'ip', type: 'string', nullable: true, example: '172.18.0.1'),
        new OA\Property(property: 'request_id', type: 'string', format: 'uuid', nullable: true),
    ],
)]
class AuditLogResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'event_at' => $this->event_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'actor' => $this->actor_id === null ? null : [
                'id' => $this->actor_id,
                'name' => $this->actor_name,
                'role' => $this->actor_role,
            ],
            'action' => $this->action,
            'auditable' => $this->auditable_type === null ? null : [
                'type' => AuditLog::aliasFor($this->auditable_type),
                'id' => $this->auditable_id,
            ],
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'metadata' => $this->metadata,
            'ip' => $this->ip,
            'request_id' => $this->request_id,
        ];
    }
}
