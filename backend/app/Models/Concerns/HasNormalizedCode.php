<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

/** `code` gravado sem espaços nas pontas e em maiúsculas (convenção de CRUD, D.2). */
trait HasNormalizedCode
{
    public static function normalizeCode(?string $code): ?string
    {
        return $code === null ? null : mb_strtoupper(trim($code));
    }

    protected function code(): Attribute
    {
        return Attribute::set(fn (?string $value) => static::normalizeCode($value));
    }
}
