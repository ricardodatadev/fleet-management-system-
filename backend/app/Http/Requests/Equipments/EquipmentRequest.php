<?php

namespace App\Http\Requests\Equipments;

use App\Http\Requests\ResourceRequest;
use App\Models\Equipment;
use App\Models\EquipmentFamily;
use Illuminate\Validation\Rule;

/**
 * Equipamentos (D.2, v1.12). `code` em trim + maiúsculas; placa sem espaços nem hífen e em maiúsculas,
 * depois `^[A-Z0-9]{5,8}$`, única entre não excluídos sobre o valor normalizado. As consistências de
 * filial (centro de custo, responsável) são checadas no controller, com as linhas travadas.
 */
class EquipmentRequest extends ResourceRequest
{
    public function rules(): array
    {
        $id = $this->route('equipment')?->getKey();
        $numeric = fn (int $decimals, string $max) => ['numeric', "decimal:0,{$decimals}", 'min:0', "max:{$max}"];

        return [
            'code' => [...$this->requiredOnCreate(), 'string', 'max:30', Rule::unique('equipments', 'code')->whereNull('deleted_at')->ignore($id)],
            'name' => [...$this->requiredOnCreate(), 'string', 'max:150'],
            'family_id' => [...$this->requiredOnCreate(), 'integer', Rule::exists('equipment_families', 'id')->whereNull('deleted_at')],
            'branch_id' => [...$this->requiredOnCreate(), 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'cost_center_id' => [...$this->requiredOnCreate(), 'integer', Rule::exists('cost_centers', 'id')->whereNull('deleted_at')],
            'responsible_employee_id' => ['sometimes', 'nullable', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'plate' => ['sometimes', 'nullable', 'string', 'regex:'.Equipment::PLATE_REGEX, Rule::unique('equipments', 'plate')->whereNull('deleted_at')->ignore($id)],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:80'],
            'model' => ['sometimes', 'nullable', 'string', 'max:80'],
            'year' => ['sometimes', 'nullable', 'integer', 'between:'.Equipment::MIN_YEAR.','.Equipment::maxYear()],
            'status' => ['sometimes', 'required', 'string', Rule::in(Equipment::STATUSES)],
            'criticality_override' => ['sometimes', 'nullable', 'string', Rule::in(EquipmentFamily::CRITICALITIES)],
            // numeric(12,1) e numeric(14,2), ≥ 0
            'odometer_km' => ['sometimes', 'required', ...$numeric(1, '99999999999.9')],
            'hour_meter' => ['sometimes', 'required', ...$numeric(1, '99999999999.9')],
            'acquisition_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'acquisition_value' => ['sometimes', 'nullable', ...$numeric(2, '999999999999.99')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return ['plate.regex' => __('api.equipment_plate_format')];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation(); // code: trim + maiúsculas
        if (is_string($this->input('plate'))) {
            $plate = Equipment::normalizePlate($this->input('plate'));
            $this->merge(['plate' => $plate === '' ? null : $plate]);
        }
    }
}
