<?php

namespace App\Http\Requests\EquipmentFamilies;

use App\Http\Requests\ResourceRequest;
use App\Models\EquipmentFamily;
use Illuminate\Validation\Rule;

class EquipmentFamilyRequest extends ResourceRequest
{
    public function rules(): array
    {
        /** @var EquipmentFamily|null $family */
        $family = $this->route('equipment_family');
        $tolerance = ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647'];

        return [
            'code' => [...$this->requiredOnCreate(), 'string', 'max:30', Rule::unique('equipment_families', 'code')->whereNull('deleted_at')->ignore($family?->getKey())],
            'name' => [...$this->requiredOnCreate(), 'string', 'max:120'],
            'category' => [...$this->requiredOnCreate(), 'string', Rule::in(EquipmentFamily::CATEGORIES)],
            'criticality' => ['sometimes', 'required', 'string', Rule::in(EquipmentFamily::CRITICALITIES)],
            // numeric(5,2) com CHECK 0 < x ≤ 100
            'preventive_lead_pct' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'gt:0', 'lte:100'],
            'tolerance_km' => $tolerance,
            'tolerance_hours' => $tolerance,
            'tolerance_days' => $tolerance,
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
