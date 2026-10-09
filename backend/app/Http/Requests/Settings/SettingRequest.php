<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\ApiFormRequest;
use App\Models\Setting;
use App\Support\Api\FieldMessage;
use App\Support\Settings\SettingsService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PUT /settings/{key} (spec F.2): chave do registry, escopo permitido para a chave, scope_id coerente com o
 * escopo (null no global; filial/família existente e não excluída) e valor do tipo da chave (enum: um dos
 * valores; bool: true/false JSON, sem conversão de "1"/"sim").
 */
class SettingRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'scope_type' => ['required', 'string', Rule::in(Setting::SCOPES)],
            'scope_id' => ['present', 'nullable', 'integer'],
            'value' => ['present'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $key = $this->settingKey();
            $definition = SettingsService::definition($key);
            if ($definition === null) {
                $validator->errors()->add('key', __('api.setting_unknown_key', ['key' => $key]));

                return;
            }
            if ($validator->errors()->hasAny(['scope_type', 'scope_id', 'value'])) {
                return;
            }

            $scopeType = (string) $this->input('scope_type');
            $scopeId = $this->input('scope_id');
            if (! in_array($scopeType, $definition['scopes'], true)) {
                $scopes = array_map(fn (string $scope) => __("api.setting_scopes.{$scope}"), $definition['scopes']);
                $validator->errors()->add('scope_type', __('api.setting_scope_not_allowed', ['scopes' => implode(', ', $scopes)]));
            } elseif ($scopeType === 'global' && $scopeId !== null) {
                $validator->errors()->add('scope_id', __('api.setting_global_scope_id'));
            } elseif ($scopeType !== 'global' && $scopeId === null) {
                $validator->errors()->add('scope_id', FieldMessage::for('required', 'scope_id'));
            } elseif ($scopeType !== 'global' && ! SettingsService::scopeEntityExists($scopeType, (int) $scopeId)) {
                $validator->errors()->add('scope_id', FieldMessage::for('exists', 'scope_id'));
            }

            $value = $this->input('value');
            $valid = $definition['type'] === 'bool'
                ? is_bool($value)
                : is_string($value) && array_key_exists($value, $definition['values']);
            if (! $valid) {
                $validator->errors()->add('value', $definition['type'] === 'bool'
                    ? __('api.setting_value_bool')
                    : __('api.setting_value_enum', ['values' => implode('; ', $definition['values'])]));
            }
        }];
    }

    public function settingKey(): string
    {
        return (string) $this->route('key');
    }
}
