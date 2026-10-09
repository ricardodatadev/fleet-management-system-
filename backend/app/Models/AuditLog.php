<?php

namespace App\Models;

use App\Exceptions\AuditImmutableException;
use App\Models\Builders\AuditLogBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de auditoria: somente leitura após criado (escrita só via AuditService).
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_logs';

    protected $guarded = [];

    /**
     * Alias curto do tipo auditável (filtro `auditable_type` e campo `auditable.type` de /audit-logs) →
     * model. Cada cadastro que usa Auditable acrescenta o seu (F1-13..16).
     *
     * @var array<string, class-string<Model>>
     */
    public const AUDITABLE_TYPES = [
        'branch' => Branch::class,
        'cost_center' => CostCenter::class,
        'user' => User::class,
    ];

    /** Valor gravado em auditable_type (morph class) para o alias; null se o alias não existir. */
    public static function morphClassFor(string $alias): ?string
    {
        $class = self::AUDITABLE_TYPES[$alias] ?? null;

        return $class === null ? null : (new $class)->getMorphClass();
    }

    /** Alias do auditable_type gravado; tipo sem alias sai como gravado. */
    public static function aliasFor(?string $morphClass): ?string
    {
        if ($morphClass === null) {
            return null;
        }
        foreach (array_keys(self::AUDITABLE_TYPES) as $alias) {
            if (self::morphClassFor($alias) === $morphClass) {
                return $alias;
            }
        }

        return $morphClass;
    }

    protected function casts(): array
    {
        return [
            'event_at' => 'immutable_datetime',
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
        ];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new AuditImmutableException('alterar');
        }

        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new AuditImmutableException('alterar');
    }

    public function delete(): ?bool
    {
        throw new AuditImmutableException('remover');
    }

    public function newEloquentBuilder($query): AuditLogBuilder
    {
        return new AuditLogBuilder($query);
    }
}
