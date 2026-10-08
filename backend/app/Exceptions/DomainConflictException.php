<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Conflito de regra de negócio (HTTP 409), ex.: excluir registro com dependentes.
 */
class DomainConflictException extends RuntimeException
{
    public function __construct(string $message = '', public readonly ?array $errors = null)
    {
        parent::__construct($message !== '' ? $message : __('api.409'));
    }
}
