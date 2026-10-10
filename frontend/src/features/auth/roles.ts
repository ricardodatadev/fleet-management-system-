import type { Role } from './types';

/** Rótulos dos perfis (spec E). Só para exibição: permissões vêm de /auth/me. */
export const ROLE_LABELS: Record<Role, string> = {
  operator: 'Motorista/Operador',
  mechanic: 'Mecânico',
  leader: 'Líder/Plantonista',
  admin: 'PCM/Gestor/Admin',
};
