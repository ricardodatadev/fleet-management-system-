<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

/**
 * Login (D.2 v1.8): só username + senha; o e-mail serve apenas para a recuperação de senha. Sem
 * validação de formato aqui (só required|string|max), para um valor fora do padrão, inclusive um
 * e-mail digitado, cair no mesmo 422 genérico de credencial inválida. `{login}`/`{email}` → 422.
 */
class LoginRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }

    /** Username normalizado como é gravado (minúsculas, sem espaços nas pontas). */
    public function username(): string
    {
        return self::normalize($this->input('username'));
    }

    /** Mesma normalização usada pelos throttles (login e esqueci a senha). */
    public static function normalize(mixed $value): string
    {
        return is_string($value) ? mb_strtolower(trim($value)) : '';
    }
}
