import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Eraser } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { ApiError, GENERIC_ERROR_MESSAGE, api } from '@/api';
import type { QueryParams } from '@/api';
import {
  Badge,
  Button,
  Combobox,
  DataTable,
  FormField,
  Input,
  PageHeader,
  Select,
} from '@/components/ui';
import type { ComboboxOption, DataTableColumn } from '@/components/ui';
import { PER_PAGE, SEARCH_DEBOUNCE_MS } from '@/features/cadastros/crud/CrudPage';
import type { User } from '@/features/cadastros/types';
import { AuditDrawer } from './AuditDrawer';
import { actorText, entityText } from './describe';
import { changedFieldsSummary } from './diff';
import { formatEventAt, periodEnd, periodStart } from './format';
import { ACTION_LABELS, ENTITY_LABELS, labelFor } from './labels';
import type { AuditLog } from './types';

/** Filtros na URL (deep-link); `from`/`to` ficam como data (yyyy-mm-dd), o que o usuário escolheu. */
const FILTERS = ['auditable_type', 'auditable_id', 'actor_id', 'action', 'from', 'to'] as const;

export const PERIOD_ERROR = 'A data final deve ser igual ou posterior à inicial.';
const ACTOR_LOOKUP_SIZE = 20;

const toOptions = (labels: Record<string, string>) =>
  Object.entries(labels).map(([value, label]) => ({ value, label }));

const userOption = (user: User): ComboboxOption => ({
  value: String(user.id),
  label: `${user.name} (${user.username})`,
});

function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : GENERIC_ERROR_MESSAGE;
}

/**
 * Auditoria (G.2-6, F2-20): trilha somente leitura, filtrável por entidade (+ id), ator, ação e
 * período, com paginação server-side e ordem fixa da API (mais recente primeiro). A linha abre o
 * detalhe com o diff old/new num drawer (`?id=`). A rota exige `audit.view` (só admin).
 */
export function AuditPage() {
  const [params, setParams] = useSearchParams();
  const page = Math.max(1, Number(params.get('page')) || 1);
  const drawerId = Number(params.get('id')) || null;
  const value = (name: (typeof FILTERS)[number]) => params.get(name) ?? '';
  const from = value('from');
  const to = value('to');
  const periodInvalid = from !== '' && to !== '' && from > to;

  // Mesmo padrão do CrudPage: a URL mais recente num ref (o updater do RR6 pode estar velho).
  const latestParams = useRef(params);
  useEffect(() => {
    latestParams.current = params;
  }, [params]);

  /** Atualiza a URL; filtro novo volta para a página 1 (o `id` do drawer só muda sozinho). */
  function updateParams(changes: Record<string, string | null>) {
    const next = new URLSearchParams(latestParams.current);
    for (const [key, val] of Object.entries(changes)) {
      if (val === null || val === '') next.delete(key);
      else next.set(key, val);
    }
    if (!('page' in changes) && !('id' in changes)) next.delete('page');
    latestParams.current = next;
    setParams(next, { replace: true });
  }

  // ID do registro com debounce (digitação), como a busca das listas.
  const auditableId = value('auditable_id');
  const [idText, setIdText] = useState(auditableId);
  const [prevId, setPrevId] = useState(auditableId);
  if (prevId !== auditableId) {
    setPrevId(auditableId);
    setIdText(auditableId);
  }
  useEffect(() => {
    const trimmed = idText.trim();
    if (trimmed === auditableId) return;
    const timer = setTimeout(() => updateParams({ auditable_id: trimmed }), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [idText, auditableId]);

  // Ator: rótulo do escolhido; no deep-link (`?actor_id=`) vem de /users/{id} (com fallback abaixo).
  const actorId = value('actor_id');
  const [picked, setPicked] = useState<ComboboxOption | null>(null);
  const actorLookup = useQuery({
    queryKey: ['/users', 'detail', Number(actorId)],
    queryFn: ({ signal }) => api.get<User>(`/users/${actorId}`, { signal }),
    enabled: actorId !== '' && picked?.value !== actorId,
    retry: false,
    select: userOption,
  });

  const query: QueryParams = {
    page,
    per_page: PER_PAGE,
    auditable_type: value('auditable_type') || null,
    auditable_id: auditableId || null,
    actor_id: actorId || null,
    action: value('action') || null,
    from: from ? periodStart(from) : null,
    to: to ? periodEnd(to) : null,
  };

  const list = useQuery({
    queryKey: ['/audit-logs', 'list', query],
    queryFn: ({ signal }) => api.getPage<AuditLog>('/audit-logs', { query, signal }),
    placeholderData: keepPreviousData,
    enabled: !periodInvalid,
  });

  // Usuário excluído dá 404 em /users/{id}: usa o nome gravado no evento (snapshot), se houver.
  const snapshotName = list.data?.data.find((row) => String(row.actor?.id) === actorId)?.actor
    ?.name;
  const actor: ComboboxOption | null =
    actorId === ''
      ? null
      : picked?.value === actorId
        ? picked
        : (actorLookup.data ?? {
            value: actorId,
            label: snapshotName ?? `Usuário #${actorId}`,
          });

  const columns: DataTableColumn<AuditLog>[] = [
    {
      key: 'event_at',
      header: 'Data e hora',
      className: 'whitespace-nowrap',
      cell: (row) => formatEventAt(row.event_at),
    },
    { key: 'actor', header: 'Ator', cell: (row) => actorText(row.actor) },
    {
      key: 'action',
      header: 'Ação',
      cell: (row) => <Badge tone="info">{labelFor(ACTION_LABELS, row.action)}</Badge>,
    },
    { key: 'entity', header: 'Entidade', cell: (row) => entityText(row.auditable) },
    { key: 'changes', header: 'Campos', cell: (row) => changedFieldsSummary(row) },
  ];

  const hasFilters = FILTERS.some((name) => params.get(name));

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Auditoria"
        description="Trilha de alterações e acessos, somente leitura. Abra um evento para ver o antes e o depois."
      />

      <div
        role="search"
        aria-label="Filtros da auditoria"
        className="flex flex-wrap items-end gap-4"
      >
        <FormField label="Entidade" className="w-full sm:w-56">
          <Select
            value={value('auditable_type')}
            placeholder="Todas"
            options={toOptions(ENTITY_LABELS)}
            onChange={(event) => updateParams({ auditable_type: event.target.value })}
          />
        </FormField>
        <FormField label="ID do registro" className="w-full sm:w-40">
          <Input
            type="number"
            inputMode="numeric"
            min={1}
            value={idText}
            onChange={(event) => setIdText(event.target.value)}
          />
        </FormField>
        <FormField label="Ator" className="w-full sm:w-72">
          {(control) => (
            <Combobox
              {...control}
              value={actor}
              placeholder="Nome ou usuário"
              loadOptions={async (q, signal) => {
                const result = await api.getPage<User>('/users', {
                  query: { q, per_page: ACTOR_LOOKUP_SIZE, sort: 'name', with_trashed: 1 },
                  signal,
                });
                return result.data.map(userOption);
              }}
              onChange={(option) => {
                setPicked(option);
                updateParams({ actor_id: option?.value ?? null });
              }}
            />
          )}
        </FormField>
        <FormField label="Ação" className="w-full sm:w-56">
          <Select
            value={value('action')}
            placeholder="Todas"
            options={toOptions(ACTION_LABELS)}
            onChange={(event) => updateParams({ action: event.target.value })}
          />
        </FormField>
        <FormField label="De" className="w-full sm:w-44">
          <Input
            type="date"
            value={from}
            max={to || undefined}
            onChange={(event) => updateParams({ from: event.target.value })}
          />
        </FormField>
        <FormField
          label="Até"
          className="w-full sm:w-44"
          error={periodInvalid ? PERIOD_ERROR : null}
        >
          <Input
            type="date"
            value={to}
            min={from || undefined}
            onChange={(event) => updateParams({ to: event.target.value })}
          />
        </FormField>
        {hasFilters && (
          <Button
            variant="secondary"
            onClick={() => {
              setPicked(null);
              setIdText('');
              updateParams(Object.fromEntries(FILTERS.map((name) => [name, null])));
            }}
          >
            <Eraser aria-hidden="true" className="size-5" />
            Limpar filtros
          </Button>
        )}
      </div>

      <DataTable
        caption="Eventos de auditoria"
        columns={columns}
        rows={list.data?.data ?? []}
        rowKey={(row) => row.id}
        meta={list.data?.meta}
        onPageChange={(next) => updateParams({ page: String(next) })}
        loading={!periodInvalid && list.isPending}
        error={
          periodInvalid
            ? 'Corrija o período para ver os eventos.'
            : list.isError
              ? errorMessage(list.error)
              : null
        }
        onRetry={periodInvalid ? undefined : () => void list.refetch()}
        onRowClick={(row) => updateParams({ id: String(row.id) })}
        emptyTitle={hasFilters ? 'Nenhum evento para os filtros' : 'Nenhum evento registrado'}
        emptyDescription={hasFilters ? 'Ajuste os filtros ou o período.' : undefined}
      />

      {drawerId !== null && (
        <AuditDrawer key={drawerId} id={drawerId} onClose={() => updateParams({ id: null })} />
      )}
    </div>
  );
}
