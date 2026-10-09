<?php

namespace App\Models;

use App\Enums\Role;
use App\Support\Audit\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Usuário (spec C.1). NUNCA usa BranchScoped (alerta da F1-10): o BranchScope chama Auth::user() e o
 * Sanctum carrega o User durante a própria autenticação. Filtro por filial em /users é explícito.
 */
#[Fillable(['name', 'email', 'password', 'role', 'branch_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /** last_login_at muda a cada login e já é coberto pelo evento login_succeeded. */
    public array $auditExclude = ['password', 'remember_token', 'updated_at', 'last_login_at'];

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** E-mail sempre em minúsculas (o login normaliza igual; o UQ parcial em email basta). */
    protected function email(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)));
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        // withTrashed: o usuário continua mostrando a filial mesmo que ela tenha sido excluída (lógica).
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->role->permissions();
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /** @return HasOne<Employee, $this> */
    public function employee(): HasOne
    {
        // Sem o escopo de filial: o vínculo é 1:1 e vale para qualquer perfil (admin pode ter filial null).
        return $this->hasOne(Employee::class)->withoutGlobalScopes([Scopes\BranchScope::class]);
    }

    /**
     * Dependentes ativos que impedem a exclusão (409): colaborador vinculado não excluído.
     *
     * @return list<string> chaves de tradução em api.dependents.*
     */
    public function activeDependents(): array
    {
        return array_keys(array_filter([
            'employees' => $this->employee()->exists(),
        ]));
    }
}
