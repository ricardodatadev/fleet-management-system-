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
