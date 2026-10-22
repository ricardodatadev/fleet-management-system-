import type { AuditAction, AuditableType } from './types';

/** Rótulos das ações (Record completo: uma ação nova na OpenAPI quebra o typecheck aqui). */
export const ACTION_LABELS: Record<AuditAction, string> = {
  created: 'Criação',
  updated: 'Alteração',
  deleted: 'Exclusão',
  restored: 'Restauração',
  login_succeeded: 'Login',
  login_failed: 'Login com falha',
  logout: 'Logout',
  password_changed: 'Troca de senha',
  password_reset_requested: 'Pedido de redefinição de senha',
  password_reset: 'Redefinição de senha',
  setting_changed: 'Parâmetro alterado',
  setting_removed: 'Parâmetro removido',
};

/** Entidades auditáveis (aliases curtos da API). */
export const ENTITY_LABELS: Record<AuditableType, string> = {
  branch: 'Unidade/Filial',
  cost_center: 'Centro de custo',
  employee: 'Colaborador',
  equipment: 'Equipamento',
  equipment_family: 'Família/Classe',
  setting: 'Parâmetro',
  user: 'Usuário',
};

/**
 * Nomes dos campos mais comuns em old/new/metadata. Campo sem rótulo aparece pelo nome técnico,
 * que também fica visível ao lado do rótulo (o auditor precisa do nome exato).
 */
export const FIELD_LABELS: Record<string, string> = {
  acquisition_date: 'Data de aquisição',
  acquisition_value: 'Valor de aquisição',
  branch_id: 'Filial',
  category: 'Categoria',
  cnh_category: 'Categoria da CNH',
  cnh_expires_at: 'Validade da CNH',
  code: 'Código',
  created_at: 'Criado em',
  cost_center_id: 'Centro de custo',
  criticality: 'Criticidade',
  criticality_override: 'Criticidade do equipamento',
  deleted_at: 'Excluído em',
  description: 'Descrição',
  document: 'Documento',
  email: 'E-mail',
  employee_id: 'Colaborador',
  family_id: 'Família/Classe',
  hour_meter: 'Horímetro',
  id: 'ID',
  is_active: 'Ativo',
  job_type: 'Função',
  key: 'Chave',
  manufacturer: 'Fabricante',
  model: 'Modelo',
  name: 'Nome',
  notes: 'Observações',
  odometer_km: 'Odômetro (km)',
  phone: 'Telefone',
  plate: 'Placa',
  reason: 'Motivo',
  registration: 'Matrícula',
  responsible_employee_id: 'Responsável',
  role: 'Perfil',
  scope_id: 'ID do escopo',
  scope_type: 'Escopo',
  serial_number: 'Nº de série',
  soft_delete: 'Exclusão reversível',
  status: 'Status',
  type: 'Tipo',
  updated_at: 'Atualizado em',
  user_id: 'Usuário',
  username: 'Usuário (login)',
  value: 'Valor',
  year: 'Ano',
};

export function labelFor<K extends string>(labels: Partial<Record<K, string>>, value: K): string {
  return labels[value] ?? value;
}
