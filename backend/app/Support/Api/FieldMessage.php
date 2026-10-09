<?php

namespace App\Support\Api;

use Illuminate\Support\Facades\Lang;

/**
 * Mensagens de validação montadas fora do Validator (checagens na transação, regras do `after()`),
 * com o nome do campo em pt_BR (`validation.attributes`), igual ao que o Validator faria.
 */
final class FieldMessage
{
    /** Nome do campo para exibição; sem tradução, troca `_` por espaço. */
    public static function label(string $field): string
    {
        $key = "validation.attributes.{$field}";

        return Lang::has($key) ? (string) __($key) : str_replace('_', ' ', $field);
    }

    /**
     * Mensagem de uma regra do validation.php ou de uma chave própria (ex.: `api.x`).
     *
     * @param  array<string, string>  $replace
     */
    public static function for(string $key, string $field, array $replace = []): string
    {
        $key = str_contains($key, '.') ? $key : "validation.{$key}";

        return (string) __($key, ['attribute' => self::label($field), ...$replace]);
    }
}
