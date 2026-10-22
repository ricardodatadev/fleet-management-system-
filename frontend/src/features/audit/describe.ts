import { ROLE_LABELS } from '@/features/auth';
import { ENTITY_LABELS, labelFor } from './labels';
import type { AuditLog } from './types';

/** Ator do snapshot (nome e perfil da época); sem ator = ação do sistema ou login sem usuário. */
export function actorText(actor: AuditLog['actor']): string {
  if (!actor) return 'Sistema';
  const name = actor.name ?? `Usuário #${actor.id}`;
  return actor.role ? `${name} (${labelFor(ROLE_LABELS, actor.role)})` : name;
}

/** Entidade afetada: rótulo do alias + id (alias desconhecido aparece cru). */
export function entityText(auditable: AuditLog['auditable']): string {
  if (!auditable) return '—';
  const label = labelFor(ENTITY_LABELS as Record<string, string>, auditable.type);
  return auditable.id === null ? label : `${label} #${auditable.id}`;
}
