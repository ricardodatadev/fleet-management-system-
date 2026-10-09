<?php

namespace App\Http\Requests\Users;

use App\Enums\Role;
use App\Http\Requests\ResourceRequest;
use App\Models\User;
use App\Support\Api\FieldMessage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Usuários (D.2, contrato v1.3). E-mail em minúsculas e único entre não excluídos; senha pela política
 * da D.2 (obrigatória no create); `branch_id` obrigatório quando o perfil resultante não é admin
 * (inclusive ao trocar de admin para outro perfil sem enviar a filial).
 */
class UserRequest extends ResourceRequest
{
    public function rules(): array
    {
        return [
            'name' => [...$this->requiredOnCreate(), 'string', 'max:120'],
            'email' => [...$this->requiredOnCreate(), 'string', 'email', 'max:190', Rule::unique('users', 'email')->whereNull('deleted_at')->ignore($this->target()?->getKey())],
            'password' => [...$this->requiredOnCreate(), 'string', 'max:255', Password::defaults()],
            'role' => [...$this->requiredOnCreate(), Rule::enum(Role::class)],
            // filial existente e não excluída (inativa é aceita); null só para admin (ver after())
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['role', 'branch_id'])) {
                return;
            }
            $target = $this->target();
            $role = $this->has('role') ? Role::tryFrom((string) $this->input('role')) : $target?->role;
            $branchId = $this->has('branch_id') ? $this->input('branch_id') : $target?->branch_id;

            if ($role !== null && $role !== Role::Admin && $branchId === null) {
                $validator->errors()->add('branch_id', FieldMessage::for('required', 'branch_id'));
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    private function target(): ?User
    {
        $user = $this->route('user');

        return $user instanceof User ? $user : null;
    }
}
