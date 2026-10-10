import { useQuery } from '@tanstack/react-query';
import { api } from '@/api';
import type { SelectOption } from '@/components/ui';
import type { Branch, CostCenter, Employee, EquipmentFamily, MetaEnums, User } from './types';

/** Limite de itens dos selects de lookup (D.1: até 200 com `is_active=1`). */
export const LOOKUP_PER_PAGE = 200;

export function useMetaEnums() {
  return useQuery({
    queryKey: ['meta', 'enums'],
    queryFn: ({ signal }) => api.get<{ enums: MetaEnums }>('/meta/enums', { signal }),
    select: (data) => data.enums,
    staleTime: Infinity,
  });
}

/** Filiais ativas para selects (`{endpoint}` no início da chave: invalidada junto com a lista). */
export function useBranchOptions(enabled = true) {
  return useQuery({
    queryKey: ['/branches', 'lookup'],
    queryFn: ({ signal }) =>
      api.getPage<Branch>('/branches', {
        query: { per_page: LOOKUP_PER_PAGE, is_active: 1, sort: 'name' },
        signal,
      }),
    select: (page): SelectOption[] =>
      page.data.map((branch) => ({
        value: String(branch.id),
        label: `${branch.code} — ${branch.name}`,
      })),
    enabled,
  });
}

/** Centros de custo ativos (com a filial, para filtrar pela filial escolhida no form). */
export function useCostCenterLookup(enabled = true) {
  return useQuery({
    queryKey: ['/cost-centers', 'lookup'],
    queryFn: ({ signal }) =>
      api.getPage<CostCenter>('/cost-centers', {
        query: { per_page: LOOKUP_PER_PAGE, is_active: 1, sort: 'code' },
        signal,
      }),
    select: (page) => page.data,
    enabled,
  });
}

/** Usuários ativos para o vínculo do colaborador (só admin enxerga /users). */
export function useUserLookup(enabled = true) {
  return useQuery({
    queryKey: ['/users', 'lookup'],
    queryFn: ({ signal }) =>
      api.getPage<User>('/users', {
        query: { per_page: LOOKUP_PER_PAGE, is_active: 1, sort: 'name' },
        signal,
      }),
    select: (page) => page.data,
    enabled,
  });
}

/** Famílias/classes ativas para selects (exige equipment_families.view). */
export function useFamilyOptions(enabled = true) {
  return useQuery({
    queryKey: ['/equipment-families', 'lookup'],
    queryFn: ({ signal }) =>
      api.getPage<EquipmentFamily>('/equipment-families', {
        query: { per_page: LOOKUP_PER_PAGE, is_active: 1, sort: 'name' },
        signal,
      }),
    select: (page): SelectOption[] =>
      page.data.map((family) => ({
        value: String(family.id),
        label: `${family.code} — ${family.name}`,
      })),
    enabled,
  });
}

/** Colaboradores ativos de uma filial (responsável pelo equipamento); sem filial, nada a buscar. */
export function useEmployeeOptions(branchId: string, enabled = true) {
  return useQuery({
    queryKey: ['/employees', 'lookup', branchId],
    queryFn: ({ signal }) =>
      api.getPage<Employee>('/employees', {
        query: { per_page: LOOKUP_PER_PAGE, is_active: 1, branch_id: branchId, sort: 'name' },
        signal,
      }),
    select: (page): SelectOption[] =>
      page.data.map((employee) => ({
        value: String(employee.id),
        label: `${employee.name} (${employee.registration})`,
      })),
    enabled: enabled && branchId !== '',
  });
}
