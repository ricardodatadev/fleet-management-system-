<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\ApiFormRequest;

class ForgotPasswordRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:190'],
        ];
    }

    /** E-mail normalizado como é gravado (minúsculas, sem espaços nas pontas). */
    public function email(): string
    {
        return mb_strtolower(trim($this->string('email')->toString()));
    }
}
