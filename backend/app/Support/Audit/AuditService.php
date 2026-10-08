<?php

namespace App\Support\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AuditService
{
    /** Chave do pg_advisory_xact_lock que serializa a escrita da cadeia de hash. */
    public const LOCK_KEY = 8_472_011_001;

    /**
     * Registra um evento. Insere na transação corrente (se houver) — o log só existe se a mudança for
     * confirmada. Fora de transação abre uma própria.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @param  array<string, mixed>  $metadata
     */
    public function record(AuditAction|string $action, ?Model $auditable = null, ?array $old = null, ?array $new = null, array $metadata = []): AuditLog
    {
        $action = $action instanceof AuditAction ? $action : AuditAction::from($action);
        $type = $auditable?->getMorphClass();
        if ($type !== null && strlen($type) > 60) {
            throw new InvalidArgumentException("auditable_type excede 60 caracteres: {$type}");
        }

        $request = AuditContext::httpRequest();
        $actor = auth()->user();

        $fields = [
            'uuid' => (string) Str::uuid(),
            'event_at' => AuditHasher::eventAt(CarbonImmutable::now('UTC')),
            'source' => AuditContext::source(),
            'actor_id' => $actor?->getAuthIdentifier(),
            'actor_name' => $actor === null ? null : $this->limit((string) data_get($actor, 'name'), 120),
            'actor_role' => $actor === null ? null : $this->role($actor),
            'action' => $action->value,
            'auditable_type' => $type,
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'metadata' => $metadata === [] ? null : $metadata,
            'ip' => $this->ip($request?->ip()),
            'user_agent' => $request === null ? null : $this->limit((string) $request->userAgent(), 255),
            'request_id' => $request?->attributes->get('request_id'),
        ];

        return DB::transaction(function () use ($fields) {
            DB::select('select pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

            $prev = DB::table('audit_logs')->orderByDesc('id')->value('hash') ?? AuditHasher::GENESIS;
            $hash = AuditHasher::hash($prev, $fields);

            $json = fn ($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $id = DB::table('audit_logs')->insertGetId([
                'uuid' => $fields['uuid'],
                'event_at' => CarbonImmutable::parse($fields['event_at'])->format('Y-m-d H:i:s.uP'),
                'source' => $fields['source'],
                'actor_id' => $fields['actor_id'],
                'actor_name' => $fields['actor_name'],
                'actor_role' => $fields['actor_role'],
                'action' => $fields['action'],
                'auditable_type' => $fields['auditable_type'],
                'auditable_id' => $fields['auditable_id'],
                'old_values' => $json($fields['old_values']),
                'new_values' => $json($fields['new_values']),
                'metadata' => $json($fields['metadata']),
                'ip' => $fields['ip'],
                'user_agent' => $fields['user_agent'],
                'request_id' => $fields['request_id'],
                'prev_hash' => $prev,
                'hash' => $hash,
            ]);

            return AuditLog::query()->findOrFail($id);
        });
    }

    private function role(object $actor): ?string
    {
        $role = data_get($actor, 'role');

        return $role instanceof BackedEnum ? (string) $role->value : ($role === null ? null : $this->limit((string) $role, 20));
    }

    private function limit(string $value, int $max): string
    {
        return mb_substr($value, 0, $max);
    }

    /** Mesma forma textual que o PostgreSQL devolve para inet (IPv6 comprimido). */
    private function ip(?string $ip): ?string
    {
        if ($ip === null || ($packed = @inet_pton($ip)) === false) {
            return null;
        }

        return inet_ntop($packed);
    }
}
