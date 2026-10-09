<?php

namespace App\Http\Requests\Employees;

use App\Http\Requests\ResourceRequest;
use App\Models\Employee;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Colaboradores (D.2, contrato v1.5). Campos de CNH só para `driver`; `specialty`/`hourly_cost` só para
 * `mechanic`: valor não nulo em campo de outro tipo → 422. Ao trocar o `job_type`, os campos do tipo
 * anterior que estiverem preenchidos precisam vir como null no mesmo payload (sem limpeza silenciosa).
 * As consistências de filial (centro de custo, usuário vinculado) são checadas no controller, com as
 * linhas travadas na transação.
 */
class EmployeeRequest extends ResourceRequest
{
    public function rules(): array
    {
        $target = $this->target();

        return [
            'registration' => [...$this->requiredOnCreate(), 'string', 'max:30', Rule::unique('employees', 'registration')->whereNull('deleted_at')->ignore($target?->getKey())],
            'name' => [...$this->requiredOnCreate(), 'string', 'max:120'],
            'job_type' => [...$this->requiredOnCreate(), 'string', Rule::in(Employee::JOB_TYPES)],
            // filial existente e não excluída (inativa é aceita)
            'branch_id' => [...$this->requiredOnCreate(), 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'cost_center_id' => ['sometimes', 'nullable', 'integer', Rule::exists('cost_centers', 'id')->whereNull('deleted_at')],
            // usuário existente, não excluído e ainda não vinculado a outro colaborador não excluído
            'user_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
                Rule::unique('employees', 'user_id')->whereNull('deleted_at')->ignore($target?->getKey()),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'hired_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'cnh_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'cnh_category' => ['sometimes', 'nullable', 'string', Rule::in(Employee::CNH_CATEGORIES)],
            'cnh_expires_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'specialty' => ['sometimes', 'nullable', 'string', 'max:80'],
            // numeric(10,2) com CHECK ≥ 0
            'hourly_cost' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['user_id.unique' => __('api.employee_user_taken')];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('job_type')) {
                return;
            }
            $target = $this->target();
            $type = $this->has('job_type') ? (string) $this->input('job_type') : $target?->job_type;
            $allowed = Employee::TYPE_FIELDS[$type] ?? [];

            foreach (Employee::typeFields() as $field) {
                if (in_array($field, $allowed, true)) {
                    continue;
                }
                $sent = $this->has($field);
                if ($sent && $this->input($field) !== null) {
                    // campo de outro tipo com valor
                    $validator->errors()->add($field, __('api.employee_field_not_allowed', ['attribute' => $field, 'type' => $type]));
                } elseif ($target !== null && $type !== $target->job_type && ! $sent && $target->getAttribute($field) !== null) {
                    // troca de tipo: o valor do tipo anterior precisa ser limpo explicitamente
                    $validator->errors()->add($field, __('api.employee_field_must_be_cleared', ['attribute' => $field]));
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('registration'))) {
            $this->merge(['registration' => mb_strtoupper(trim($this->input('registration')))]);
        }
    }

    private function target(): ?Employee
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee ? $employee : null;
    }
}
