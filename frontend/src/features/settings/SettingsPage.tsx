import { SlidersHorizontal } from 'lucide-react';
import { useSearchParams } from 'react-router-dom';
import { ApiError, GENERIC_ERROR_MESSAGE } from '@/api';
import { Button, EmptyState, FormField, PageHeader, Select, Skeleton } from '@/components/ui';
import type { SelectOption } from '@/components/ui';
import { useAuth, useCan } from '@/features/auth';
import { useBranchOptions, useFamilyOptions } from '@/features/cadastros/lookups';
import { SettingRow } from './SettingRow';
import { useSettingDefinitions } from './api';
import type { Scope, ScopeType } from './types';

/** Escopo na URL (deep-link), em pt-BR: `?escopo=filial&id=2`. */
const URL_SCOPE: Record<string, ScopeType> = {
  global: 'global',
  filial: 'branch',
  familia: 'family',
};
const SCOPE_URL: Record<ScopeType, string> = {
  global: 'global',
  branch: 'filial',
  family: 'familia',
};

const SCOPE_OPTIONS: SelectOption[] = [
  { value: 'global', label: 'Global' },
  { value: 'branch', label: 'Filial' },
  { value: 'family', label: 'Família' },
];

/**
 * Painel de Parâmetros do Gestor (G.4-3, F1-28): as chaves RN-001..004 do registry, com o valor
 * efetivo e a origem no escopo escolhido (Global | Filial | Família). Edição só com
 * `settings.manage`; quem só vê (líder) lê, e só a própria filial aparece no seletor.
 */
export function SettingsPage() {
  const auth = useAuth();
  const can = useCan();
  const canManage = can('settings.manage');
  const fixedBranch = auth.status === 'authenticated' ? auth.user.branch : null;
  const [params, setParams] = useSearchParams();
  const definitions = useSettingDefinitions();

  const type: ScopeType = URL_SCOPE[params.get('escopo') ?? ''] ?? 'global';
  const rawId = Number(params.get('id'));
  // Líder (filial fixa): no escopo de filial, só a própria.
  const id =
    type === 'global'
      ? null
      : type === 'branch' && fixedBranch
        ? fixedBranch.id
        : rawId > 0
          ? rawId
          : null;
  const scope: Scope = { type, id };

  const branches = useBranchOptions(type === 'branch' && fixedBranch === null);
  const families = useFamilyOptions(type === 'family');
  const branchOptions: SelectOption[] = fixedBranch
    ? [{ value: String(fixedBranch.id), label: `${fixedBranch.code} — ${fixedBranch.name}` }]
    : (branches.data ?? []);
  const entityOptions = type === 'branch' ? branchOptions : (families.data ?? []);
  const entityLoading =
    type === 'branch' ? fixedBranch === null && branches.isPending : families.isPending;
  const entityLabel = entityOptions.find((option) => option.value === String(id))?.label;
  const scopeLabel =
    type === 'global'
      ? 'Global'
      : `${type === 'branch' ? 'Filial' : 'Família'} ${entityLabel ?? ''}`.trim();

  function changeScope(next: ScopeType) {
    setParams(next === 'global' ? {} : { escopo: SCOPE_URL[next] }, { replace: true });
  }
  function changeEntity(value: string) {
    setParams(value ? { escopo: SCOPE_URL[type], id: value } : { escopo: SCOPE_URL[type] }, {
      replace: true,
    });
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Painel de Parâmetros"
        description={
          canManage
            ? 'Regras do gestor por escopo: o valor de uma família vale sobre o da filial, que vale sobre o global.'
            : 'Somente leitura: você consulta os valores, mas não pode alterá-los.'
        }
      />

      <section
        aria-labelledby="painel-gestor"
        className="flex flex-col gap-4 rounded-card border border-border bg-surface p-4 sm:p-6"
      >
        <h2 id="painel-gestor" className="text-xl font-bold text-text">
          Painel de Parâmetros do Gestor
        </h2>

        <div role="group" aria-label="Escopo" className="flex flex-wrap items-end gap-4">
          <FormField label="Escopo" className="w-full sm:w-56">
            <Select
              value={type}
              options={SCOPE_OPTIONS}
              onChange={(event) => changeScope(event.target.value as ScopeType)}
            />
          </FormField>
          {type !== 'global' && (
            <FormField label={type === 'branch' ? 'Filial' : 'Família'} className="w-full sm:w-80">
              <Select
                value={id === null ? '' : String(id)}
                disabled={entityLoading || (type === 'branch' && fixedBranch !== null)}
                placeholder={entityLoading ? 'Carregando…' : 'Selecione'}
                options={entityOptions}
                onChange={(event) => changeEntity(event.target.value)}
              />
            </FormField>
          )}
        </div>

        {definitions.isPending ? (
          <div className="flex flex-col gap-4" aria-busy="true">
            {[0, 1, 2, 3].map((i) => (
              <Skeleton key={i} className="h-20 w-full" />
            ))}
          </div>
        ) : definitions.isError ? (
          <EmptyState
            role="alert"
            title="Não foi possível carregar os parâmetros"
            description={
              definitions.error instanceof ApiError
                ? definitions.error.message
                : GENERIC_ERROR_MESSAGE
            }
            action={
              <Button variant="secondary" onClick={() => void definitions.refetch()}>
                Tentar novamente
              </Button>
            }
          />
        ) : type !== 'global' && id === null ? (
          <EmptyState
            role="status"
            icon={<SlidersHorizontal className="size-12" />}
            title={type === 'branch' ? 'Escolha uma filial' : 'Escolha uma família'}
            description="Os valores e a origem de cada parâmetro aparecem para o escopo escolhido."
          />
        ) : (
          <div>
            {definitions.data.map((definition) => (
              <SettingRow
                key={definition.key}
                definition={definition}
                scope={scope}
                scopeLabel={scopeLabel}
                canManage={canManage}
              />
            ))}
          </div>
        )}
      </section>
    </div>
  );
}
