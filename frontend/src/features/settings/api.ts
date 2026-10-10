import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/api';
import type { Scope, SettingDefinition, SettingEffective, SettingValue } from './types';

export function useSettingDefinitions() {
  return useQuery({
    queryKey: ['/settings', 'definitions'],
    queryFn: ({ signal }) => api.get<SettingDefinition[]>('/settings/definitions', { signal }),
    staleTime: Infinity,
  });
}

/** Valor efetivo de uma chave no escopo (global = sem filial/família). */
export function useEffectiveSetting(key: string, scope: Scope, enabled = true) {
  return useQuery({
    queryKey: ['/settings', 'effective', key, scope.type, scope.id],
    queryFn: ({ signal }) =>
      api.get<SettingEffective>('/settings/effective', {
        query: {
          key,
          branch_id: scope.type === 'branch' ? scope.id : null,
          family_id: scope.type === 'family' ? scope.id : null,
        },
        signal,
      }),
    enabled: enabled && (scope.type === 'global' || scope.id !== null),
  });
}

/** PUT/DELETE de override; devolvem a `message` do envelope (inclui o caso "nenhuma alteração"). */
export function useSettingMutations(key: string) {
  const queryClient = useQueryClient();
  // Um override muda o efetivo de outros escopos (ex.: global herdado nas filiais).
  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['/settings', 'effective', key] });

  const save = useMutation({
    mutationFn: async ({ scope, value }: { scope: Scope; value: SettingValue }) =>
      (
        await api.raw<unknown>('PUT', `/settings/${encodeURIComponent(key)}`, {
          body: {
            scope_type: scope.type,
            scope_id: scope.type === 'global' ? null : scope.id,
            value,
          },
        })
      ).message,
    onSuccess: invalidate,
  });

  const remove = useMutation({
    mutationFn: async (scope: Scope) =>
      (
        await api.raw<unknown>('DELETE', `/settings/${encodeURIComponent(key)}`, {
          query: { scope_type: scope.type, scope_id: scope.id },
        })
      ).message,
    onSuccess: invalidate,
  });

  return { save, remove };
}
