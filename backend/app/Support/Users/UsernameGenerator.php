<?php

namespace App\Support\Users;

use Illuminate\Support\Str;

/**
 * Username a partir do e-mail (backfill da F1-34, D.2 v1.7): parte local → Str::ascii + minúsculas →
 * remove o que não for [a-z0-9] → completa até 3 caracteres com dígitos → corta em 30, contando o
 * sufixo numérico de colisão (2, 3…). Só o backfill gera username; no cadastro ele é informado e
 * validado, sem transliteração.
 */
final class UsernameGenerator
{
    public const PATTERN = '/^[a-z0-9]{3,30}$/';

    public const MAX = 30;

    /** @param  callable(string): bool  $taken  diz se o candidato já está em uso */
    public static function fromEmail(string $email, callable $taken): string
    {
        $base = self::base($email);

        for ($n = 1; ; $n++) {
            $suffix = $n === 1 ? '' : (string) $n;
            $candidate = substr($base, 0, self::MAX - strlen($suffix)).$suffix;
            if (! $taken($candidate)) {
                return $candidate;
            }
        }
    }

    /** Base sem sufixo: ASCII, minúsculas, só [a-z0-9], mínimo de 3 caracteres (com zeros). */
    public static function base(string $email): string
    {
        $local = Str::before($email, '@');
        $base = preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($local))) ?? '';

        return str_pad($base, 3, '0');
    }
}
