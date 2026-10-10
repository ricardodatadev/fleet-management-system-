import type { components } from '@/api/schema';

export type SettingDefinition = components['schemas']['SettingDefinition'];
export type SettingEffective = components['schemas']['SettingEffective'];
export type ScopeType = 'global' | 'branch' | 'family';
/** Valor de um parâmetro: string do enum ou boolean. */
export type SettingValue = string | boolean;

/** Escopo escolhido no painel; `id` é null no global (ou enquanto a entidade não foi escolhida). */
export interface Scope {
  type: ScopeType;
  id: number | null;
}
