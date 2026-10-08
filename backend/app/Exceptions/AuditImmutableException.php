<?php

namespace App\Exceptions;

use LogicException;

/** audit_logs é append-only: nenhuma alteração/remoção é permitida. */
class AuditImmutableException extends LogicException
{
    public function __construct(string $operation = 'alterar')
    {
        parent::__construct("audit_logs é append-only: não é permitido {$operation} registros de auditoria.");
    }
}
