import { useQuery } from '@tanstack/react-query';
import { api } from '@/api';
import type { SelectOption } from '@/components/ui';
import type { Branch, CostCenter, MetaEnums, User } from './types';

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
