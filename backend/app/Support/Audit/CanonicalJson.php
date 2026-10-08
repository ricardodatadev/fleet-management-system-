<?php

namespace App\Support\Audit;

use InvalidArgumentException;

/**
 * JSON canônico para o hash do audit trail (spec F.1):
 * chaves de objetos ordenadas alfabeticamente em todos os níveis, null explícito, JSON compacto,
 * UTF-8 sem escape de "/" e de unicode, números sem notação científica. Listas mantêm a ordem.
 */
final class CanonicalJson
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @param  bool  $emptyArrayAsObject  trata [] na raiz como objeto vazio {} (campos que são sempre objetos)
     */
    public static function encode(mixed $value, bool $emptyArrayAsObject = false): string
    {
        return self::write($value, $emptyArrayAsObject);
    }

    private static function write(mixed $value, bool $emptyArrayAsObject): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return self::float($value);
        }
        if (is_string($value)) {
            return json_encode($value, self::FLAGS | JSON_THROW_ON_ERROR);
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('Valor não suportado no JSON canônico: '.get_debug_type($value));
        }

        if ($value === []) {
            return $emptyArrayAsObject ? '{}' : '[]';
        }

        if (array_is_list($value)) {
            return '['.implode(',', array_map(fn ($v) => self::write($v, false), $value)).']';
        }

        ksort($value, SORT_STRING);
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = json_encode((string) $key, self::FLAGS | JSON_THROW_ON_ERROR).':'.self::write($item, false);
        }

        return '{'.implode(',', $parts).'}';
    }

    /** Menor representação decimal que faz round-trip, sem expoente (1.0E+25 → 10000000000000000000000000.0). */
    private static function float(float $f): string
    {
        if (! is_finite($f)) {
            throw new InvalidArgumentException('Float não finito no JSON canônico.');
        }

        $s = json_encode($f, JSON_THROW_ON_ERROR);
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?[eE]([+-]?\d+)$/', $s, $m)) {
            return $s;
        }

        [, $sign, $int, $frac, $exp] = $m + [3 => ''];
        $frac = rtrim($frac, '0');
        $digits = $int.$frac;
        $point = strlen($int) + (int) $exp;
        if ($point <= 0) {
            $out = '0.'.str_repeat('0', -$point).$digits;
        } elseif ($point >= strlen($digits)) {
            $out = $digits.str_repeat('0', $point - strlen($digits)).'.0';
        } else {
            $out = substr($digits, 0, $point).'.'.substr($digits, $point);
        }

        return $sign.$out;
    }
}
