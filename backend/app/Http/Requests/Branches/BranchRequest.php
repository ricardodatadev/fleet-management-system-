<?php

namespace App\Http\Requests\Branches;

use App\Http\Requests\ResourceRequest;
use App\Models\Branch;
use Illuminate\Validation\Rule;

class BranchRequest extends ResourceRequest
{
    public function rules(): array
    {
        /** @var Branch|null $branch */
        $branch = $this->route('branch');

        return [
            'code' => [...$this->requiredOnCreate(), 'string', 'max:20', Rule::unique('branches', 'code')->whereNull('deleted_at')->ignore($branch?->getKey())],
            'name' => [...$this->requiredOnCreate(), 'string', 'max:120'],
            'type' => [...$this->requiredOnCreate(), 'string', Rule::in(Branch::TYPES)],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'state' => ['sometimes', 'nullable', 'string', Rule::in(Branch::STATES)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        if (is_string($this->input('state'))) {
            $this->merge(['state' => mb_strtoupper(trim($this->input('state')))]);
        }
    }
}
