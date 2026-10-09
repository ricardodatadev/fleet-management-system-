<?php

namespace App\Http\Resources;

use App\Models\Branch;
use App\Models\EquipmentFamily;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use OpenApi\Attributes as OA;

/**
 * Override gravado de um parâmetro. `scope` é a filial/família do escopo `{id, code, name}` (null no global).
 *
 * @mixin Setting
 */
#[OA\Schema(
    schema: 'Setting',
    required: ['id', 'key', 'scope_type', 'scope_id', 'scope', 'value', 'updated_by', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'key', type: 'string', example: 'warranty.alert_mode'),
        new OA\Property(property: 'scope_type', type: 'string', enum: Setting::SCOPES),
        new OA\Property(property: 'scope_id', type: 'integer', nullable: true),
        new OA\Property(
            property: 'scope',
            description: 'Filial ou família do escopo; null no global.',
            type: 'object',
            nullable: true,
            required: ['id', 'code', 'name'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'code', type: 'string', example: 'FIL-001'),
                new OA\Property(property: 'name', type: 'string', example: 'Matriz'),
            ],
        ),
        new OA\Property(property: 'value', description: 'Valor do tipo da chave (string do enum ou boolean).', example: 'hard_block'),
        new OA\Property(
            property: 'updated_by',
            type: 'object',
            nullable: true,
            required: ['id', 'name'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'name', type: 'string', example: 'Ana Souza'),
            ],
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
class SettingResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $scope = $this->relationLoaded('scope') ? $this->getRelation('scope') : $this->scopeEntity();

        return [
            'id' => $this->id,
            'key' => $this->key,
            'scope_type' => $this->scope_type,
            'scope_id' => $this->scope_id,
            'scope' => $scope === null ? null : ['id' => $scope->id, 'code' => $scope->code, 'name' => $scope->name],
            'value' => $this->value,
            'updated_by' => $this->updatedBy === null ? null : ['id' => $this->updatedBy->id, 'name' => $this->updatedBy->name],
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }

    /**
     * Carrega a entidade do escopo de vários overrides em 2 queries (filiais e famílias), sem N+1.
     *
     * @param  Collection<int, Setting>  $settings
     */
    public static function preloadScopes(Collection $settings): void
    {
        $ids = fn (string $type) => $settings->where('scope_type', $type)->pluck('scope_id')->unique()->all();
        $branches = Branch::withTrashed()->findMany($ids('branch'))->keyBy('id');
        $families = EquipmentFamily::withTrashed()->findMany($ids('family'))->keyBy('id');

        foreach ($settings as $setting) {
            $setting->setRelation('scope', match ($setting->scope_type) {
                'branch' => $branches->get($setting->scope_id),
                'family' => $families->get($setting->scope_id),
                default => null,
            });
        }
    }
}
