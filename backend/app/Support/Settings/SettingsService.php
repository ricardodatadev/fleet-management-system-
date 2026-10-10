<?php

namespace App\Support\Settings;

use App\Enums\AuditAction;
use App\Models\Branch;
use App\Models\EquipmentFamily;
use App\Models\Setting;
use App\Support\Api\FieldMessage;
use App\Support\Audit\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Parâmetros por escopo (spec F.2). A fonte de verdade de tipo/default/escopos é config/settings_registry.php.
 * Sem motor de regra: só a API e a tela consomem as chaves na Fase 1.
 */
class SettingsService
{
    /** Ordem de precedência na resolução (o mais específico primeiro). */
    public const PRECEDENCE = ['family', 'branch', 'global'];

    public function __construct(private readonly AuditService $audit) {}

    /** @return array<string, array<string, mixed>> */
    public static function registry(): array
    {
        return config('settings_registry', []);
    }

    /** @return array<string, mixed>|null */
    public static function definition(string $key): ?array
    {
        return self::registry()[$key] ?? null;
    }

    /**
     * Valor efetivo de uma chave: family → branch → global → default do registry, só entre os escopos
     * permitidos para a chave. `source` = {scope_type, scope_id} do override usado, ou 'default'.
     *
     * @return array{key: string, value: mixed, source: array{scope_type: string, scope_id: ?int}|string}
     */
    public function resolve(string $key, ?int $branchId = null, ?int $familyId = null): array
    {
        $definition = self::definition($key) ?? throw new InvalidArgumentException("Parâmetro desconhecido: {$key}");
        $candidates = ['family' => $familyId, 'branch' => $branchId, 'global' => null];

        $overrides = Setting::query()->where('key', $key)
            ->where(function ($q) use ($definition, $candidates) {
                foreach (self::PRECEDENCE as $scope) {
                    if (! in_array($scope, $definition['scopes'], true)) {
                        continue;
                    }
                    if ($scope === 'global') {
                        $q->orWhere(fn ($w) => $w->where('scope_type', 'global')->whereNull('scope_id'));
                    } elseif ($candidates[$scope] !== null) {
                        $q->orWhere(fn ($w) => $w->where('scope_type', $scope)->where('scope_id', $candidates[$scope]));
                    }
                }
            })
            ->get()->keyBy('scope_type');

        foreach (self::PRECEDENCE as $scope) {
            if (in_array($scope, $definition['scopes'], true) && ($setting = $overrides->get($scope)) !== null) {
                return ['key' => $key, 'value' => $setting->value, 'source' => ['scope_type' => $scope, 'scope_id' => $setting->scope_id]];
            }
        }

        return ['key' => $key, 'value' => $definition['default'], 'source' => 'default'];
    }

    /**
     * Grava (upsert idempotente) o override do escopo. Escopo, valor e existência do scope_id já foram
     * validados; aqui a entidade do escopo é travada (FOR SHARE) para não cruzar com a exclusão dela. Só
     * audita `setting_changed` quando o valor muda (ou o override é criado).
     */
    public function put(string $key, string $scopeType, ?int $scopeId, mixed $value): Setting
    {
        $attempt = function () use ($key, $scopeType, $scopeId, $value): Setting {
            return DB::transaction(function () use ($key, $scopeType, $scopeId, $value) {
                $this->lockScopeEntity($scopeType, $scopeId);
                $setting = $this->find($key, $scopeType, $scopeId, lock: true);
                $old = $setting?->value;

                if ($setting !== null && $old === $value) {
                    return $setting; // idempotente: mesmo valor, nada muda nem é auditado
                }
                $setting ??= new Setting(['key' => $key, 'scope_type' => $scopeType, 'scope_id' => $scopeId]);
                $setting->fill(['value' => $value, 'updated_by' => Auth::id()])->save();

                $this->audit->record(AuditAction::SettingChanged, $setting,
                    $setting->wasRecentlyCreated ? null : ['value' => $old],
                    ['value' => $value],
                    ['key' => $key, 'scope_type' => $scopeType, 'scope_id' => $scopeId],
                );

                return $setting;
            });
        };

        try {
            return $attempt();
        } catch (UniqueConstraintViolationException) {
            // Dois PUTs simultâneos criando o mesmo override: o segundo vira update do que acabou de entrar.
            return $attempt();
        }
    }

    /** Remove um override não global. Retorna false se ele não existir. */
    public function remove(string $key, string $scopeType, int $scopeId): bool
    {
        return DB::transaction(function () use ($key, $scopeType, $scopeId) {
            $setting = $this->find($key, $scopeType, $scopeId, lock: true);
            if ($setting === null) {
                return false;
            }
            $old = $setting->value;
            $setting->delete();
            $this->audit->record(AuditAction::SettingRemoved, $setting, ['value' => $old], null,
                ['key' => $key, 'scope_type' => $scopeType, 'scope_id' => $scopeId]);

            return true;
        });
    }

    public function find(string $key, string $scopeType, ?int $scopeId, bool $lock = false): ?Setting
    {
        $query = Setting::query()->where('key', $key)->where('scope_type', $scopeType);
        $scopeId === null ? $query->whereNull('scope_id') : $query->where('scope_id', $scopeId);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** A entidade do escopo (filial ou família) existe e não está excluída. */
    public static function scopeEntityExists(string $scopeType, int $scopeId): bool
    {
        return match ($scopeType) {
            'branch' => Branch::query()->whereKey($scopeId)->exists(),
            'family' => EquipmentFamily::query()->whereKey($scopeId)->exists(),
            default => false,
        };
    }

    private function lockScopeEntity(string $scopeType, ?int $scopeId): void
    {
        $model = match ($scopeType) {
            'branch' => Branch::class,
            'family' => EquipmentFamily::class,
            default => null,
        };
        if ($model !== null && $model::query()->whereKey($scopeId)->sharedLock()->first(['id']) === null) {
            throw ValidationException::withMessages(['scope_id' => FieldMessage::for('exists', 'scope_id')]);
        }
    }
}
