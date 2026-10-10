<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Override de parâmetro por escopo (spec C.7/F.2). Sem soft delete: a remoção de um override é auditada
 * (`setting_removed`). As mudanças são auditadas pelo SettingsService (`setting_changed`), e não pelo
 * trait Auditable (created/updated/deleted).
 *
 * @property int $id
 * @property string $key
 * @property string $scope_type
 * @property int|null $scope_id
 * @property mixed $value
 * @property int|null $updated_by
 * @property-read User|null $updatedBy
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Setting extends Model
{
    public const SCOPES = ['global', 'branch', 'family'];

    protected $fillable = ['key', 'scope_type', 'scope_id', 'value', 'updated_by'];

    protected function casts(): array
    {
        return [
            'scope_id' => 'integer',
            'value' => 'json',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    /** Entidade do escopo (filial ou família, inclusive excluída), ou null no global. */
    public function scopeEntity(): Branch|EquipmentFamily|null
    {
        return match ($this->scope_type) {
            'branch' => Branch::withTrashed()->find($this->scope_id),
            'family' => EquipmentFamily::withTrashed()->find($this->scope_id),
            default => null,
        };
    }
}
