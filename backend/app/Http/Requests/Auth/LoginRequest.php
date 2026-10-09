<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

/**
 * Login (D.2 v1.7): `login` é o e-mail (com `@`) ou o username (sem `@`). O contrato antigo `{email}` foi
 * removido: sem `login` → 422.
 */
class LoginRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }

    /** Login normalizado como é gravado (minúsculas, sem espaços nas pontas). */
    public function login(): string
    {
        return self::normalize($this->input('login'));
    }

    public function isEmail(): bool
    {
        return str_contains($this->login(), '@');
    }

    /** Mesma normalização usada pelo throttle de login. */
    public static function normalize(mixed $login): string
    {
        return is_string($login) ? mb_strtolower(trim($login)) : '';
    }
}
