import type { Scope, SettingEffective } from './types';

type Source = SettingEffective['source'];

/** O valor efetivo foi definido no próprio escopo em tela (inclui o global vendo o global)? */
export function isDefinedHere(source: Source, scope: Scope): boolean {
  return (
    source !== 'default' &&
    source.scope_type === scope.type &&
    (scope.type === 'global' || source.scope_id === scope.id)
  );
}

/** Há override removível no escopo em tela? (o global nunca é removível) */
export function isOverrideHere(source: Source, scope: Scope): boolean {
  return (
    source !== 'default' &&
    scope.type !== 'global' &&
    source.scope_type === scope.type &&
    source.scope_id === scope.id
  );
}

const HERE: Record<Scope['type'], string> = {
  global: 'Definido no global',
  branch: 'Definido nesta filial',
  family: 'Definido nesta família',
};

const INHERITED: Record<Scope['type'], string> = {
  global: 'Herdado de Global',
  branch: 'Herdado da filial',
  family: 'Herdado da família',
};

/** Origem do valor efetivo, do ponto de vista do escopo em tela (G.4-3). */
export function originLabel(source: Source, scope: Scope): string {
  if (source === 'default') return 'Padrão do sistema';
  return isDefinedHere(source, scope) ? HERE[source.scope_type] : INHERITED[source.scope_type];
}
