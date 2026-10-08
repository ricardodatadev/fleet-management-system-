<?php

namespace App\Models\Builders;

use App\Exceptions\AuditImmutableException;
use Illuminate\Database\Eloquent\Builder;

/** Bloqueia UPDATE/DELETE em massa via Eloquent (o banco também bloqueia por trigger). */
class AuditLogBuilder extends Builder
{
    public function update(array $values): int
    {
        throw new AuditImmutableException('alterar');
    }

    public function delete(): int
    {
        throw new AuditImmutableException('remover');
    }

    public function forceDelete(): int
    {
        throw new AuditImmutableException('remover');
    }
}
