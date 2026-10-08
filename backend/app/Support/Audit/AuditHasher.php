<?php

namespace App\Support\Audit;

use Carbon\CarbonImmutable;
use stdClass;

/** Campos hasheados, normalização linha↔registro e cálculo do hash encadeado. */
final class AuditHasher
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public const FIELDS = [
        'uuid', 'event_at', 'source', 'actor_id', 'actor_name', 'actor_role', 'action',
        'auditable_type', 'auditable_id', 'old_values', 'new_values', 'metadata',
        'ip', 'user_agent', 'request_id',
    ];

    private const OBJECT_FIELDS = ['old_values', 'new_values', 'metadata'];

    public static function eventAt(CarbonImmutable|string $value): string
    {
        $date = $value instanceof CarbonImmutable ? $value : CarbonImmutable::parse($value);

        return $date->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    /** JSON canônico dos campos (exclui id, prev_hash e hash). */
    public static function canonical(array $fields): string
    {
        $record = [];
        foreach (self::FIELDS as $field) {
            $value = $fields[$field] ?? null;
            // Campos JSON passam por round-trip: o que é hasheado é exatamente o que o jsonb devolve.
            if (in_array($field, self::OBJECT_FIELDS, true) && $value !== null) {
                $value = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            }
            $record[$field] = $value;
        }

        $parts = [];
        ksort($record, SORT_STRING);
        foreach ($record as $key => $value) {
            $parts[] = json_encode($key).':'.CanonicalJson::encode($value, in_array($key, self::OBJECT_FIELDS, true));
        }

        return '{'.implode(',', $parts).'}';
    }

    public static function hash(string $prevHash, array $fields): string
    {
        return hash('sha256', $prevHash.self::canonical($fields));
    }

    /** Converte uma linha lida do banco nos campos hasheados. */
    public static function fieldsFromRow(stdClass $row): array
    {
        $json = fn (?string $v) => $v === null ? null : json_decode($v, true, 512, JSON_THROW_ON_ERROR);

        return [
            'uuid' => $row->uuid,
            'event_at' => self::eventAt((string) $row->event_at),
            'source' => $row->source,
            'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
            'actor_name' => $row->actor_name,
            'actor_role' => $row->actor_role,
            'action' => $row->action,
            'auditable_type' => $row->auditable_type,
            'auditable_id' => $row->auditable_id === null ? null : (int) $row->auditable_id,
            'old_values' => $json($row->old_values),
            'new_values' => $json($row->new_values),
            'metadata' => $json($row->metadata),
            'ip' => $row->ip,
            'user_agent' => $row->user_agent,
            'request_id' => $row->request_id,
        ];
    }
}
