<?php

namespace App\Http\Requests\CostCenters;

use App\Http\Requests\ResourceRequest;
use App\Models\CostCenter;
use Illuminate\Validation\Rule;

class CostCenterRequest extends ResourceRequest
{
    public function rules(): array
    {
        /** @var CostCenter|null $costCenter */
        $costCenter = $this->route('cost_center');

        return [
            'code' => [...$this->requiredOnCreate(), 'string', 'max:30', Rule::unique('cost_centers', 'code')->whereNull('deleted_at')->ignore($costCenter?->getKey())],
            'name' => [...$this->requiredOnCreate(), 'string', 'max:120'],
            // filial existente e não excluída (inativa é aceita); null = sem filial
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
