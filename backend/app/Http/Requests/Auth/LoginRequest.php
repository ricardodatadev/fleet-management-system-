<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class LoginRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }

    /** E-mail normalizado como é gravado (minúsculas, sem espaços). */
    public function email(): string
    {
        return mb_strtolower(trim($this->string('email')->toString()));
    }
}
