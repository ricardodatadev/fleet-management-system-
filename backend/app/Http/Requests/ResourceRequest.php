<?php

namespace App\Http\Requests;

/**
 * Base dos FormRequests de cadastro. PUT e PATCH têm a mesma semântica parcial (convenção D.2):
 * no create os campos obrigatórios são `required`; no update, `sometimes` (só valida o que veio).
 */
abstract class ResourceRequest extends ApiFormRequest
{
    protected function isCreate(): bool
    {
        return $this->isMethod('POST');
    }

    /** `required` no create, `sometimes` + `required` (não pode vir vazio) no update. */
    protected function requiredOnCreate(): array
    {
        return $this->isCreate() ? ['required'] : ['sometimes', 'required'];
    }

    /** Normaliza `code` (trim + maiúsculas) antes da validação, como o model grava. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code')))]);
        }
    }
}
