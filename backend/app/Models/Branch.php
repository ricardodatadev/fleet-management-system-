<?php

namespace App\Models;

use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Unidade/Filial (spec C.2). Só a estrutura na F1-09; CRUD, Auditable e regras de exclusão na F1-12.
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    use SoftDeletes;

    public const TYPES = ['filial', 'garagem', 'oficina'];

    protected $fillable = ['code', 'name', 'type', 'city', 'state', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
