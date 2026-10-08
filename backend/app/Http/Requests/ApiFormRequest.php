<?php

namespace App\Http\Requests;

use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** FormRequest base: falhas de validação saem no envelope (422). */
abstract class ApiFormRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::validationError($validator->errors()->toArray()));
    }
}
