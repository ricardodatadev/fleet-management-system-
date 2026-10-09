import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, RotateCcw, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { ApiError, GENERIC_ERROR_MESSAGE, api } from '@/api';
import type { QueryParams } from '@/api';
import {
  Badge,
  Button,
  ConfirmDialog,
  DataTable,
  FormField,
  IconButton,
  Input,
  PageHeader,
  Select,
  Switch,
  useToast,
} from '@/components/ui';
import type { DataTableColumn, SortState } from '@/components/ui';
import { useCan } from '@/features/auth';
import { CrudFormModal } from './CrudFormModal';
import type { CrudResource, CrudRow } from './types';

export const PER_PAGE = 15;
export const SEARCH_DEBOUNCE_MS = 300;

function parseSort(value: string | null): SortState | null {
  if (!value) return null;
  return value.startsWith('-')
    ? { field: value.slice(1), direction: 'desc' }
    : { field: value, direction: 'asc' };
}

function formatSort(sort: SortState | null): string | null {
  if (!sort) return null;
  return sort.direction === 'desc' ? `-${sort.field}` : sort.field;
}

function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : GENERIC_ERROR_MESSAGE;
}

type Editing<T> = { row: T | null } | null;

/** Tela de cadastro genérica (F1-30): lista server-side, criar/editar, excluir, restaurar. */
export function CrudPage<T extends CrudRow>({ resource }: { resource: CrudResource<T> }) {
  const { endpoint, labels } = resource;
  const can = useCan();
  const canManage = can(resource.permissions.manage);
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [params, setParams] = useSearchParams();

  const filterNames = (resource.filters ?? []).map((filter) => filter.name);
  const sort = parseSort(params.get('sort'));
  const page = Math.max(1, Number(params.get('page')) || 1);
  // with_trashed só para quem tem manage (sem ela a API responde 403).
  const withTrashed = canManage && params.get('with_trashed') === '1';
  const q = params.get('q') ?? '';

  const query: QueryParams = { page, per_page: PER_PAGE, q, sort: formatSort(sort) };
  for (const name of filterNames) query[name] = params.get(name);
  if (withTrashed) query.with_trashed = 1;

  // Sempre a URL mais recente: o updater funcional do React Router 6 recebe os parâmetros do
  // render em que foi criado, e o timer da busca rodaria com uma URL velha (apagando filtros
  // escolhidos durante o debounce). O ref também compõe duas mudanças no mesmo ciclo.
  const latestParams = useRef(params);
  useEffect(() => {
    latestParams.current = params;
  }, [params]);

  /** Atualiza a URL; qualquer mudança que não seja de página volta para a página 1. */
  function updateParams(changes: Record<string, string | null>) {
    const next = new URLSearchParams(latestParams.current);
    for (const [key, value] of Object.entries(changes)) {
      if (value === null || value === '') next.delete(key);
      else next.set(key, value);
    }
    if (!('page' in changes)) next.delete('page');
    latestParams.current = next;
    setParams(next, { replace: true });
  }

  // Busca com debounce: o campo é local, a URL (e a API) só mudam após a pausa.
  const [search, setSearch] = useState(q);
  const [prevQ, setPrevQ] = useState(q);
  if (prevQ !== q) {
    setPrevQ(q);
    setSearch(q);
  }
  useEffect(() => {
    if (search.trim() === q) return;
    const timer = setTimeout(() => updateParams({ q: search.trim() }), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(timer);
    // updateParams é estável o bastante: depende só de setParams.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, q]);

  const list = useQuery({
    queryKey: [endpoint, 'list', query],
    queryFn: ({ signal }) => api.getPage<T>(endpoint, { query, signal }),
    placeholderData: keepPreviousData,
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: [endpoint] });

  // --- Criar / editar --------------------------------------------------------------------------
  const [editing, setEditing] = useState<Editing<T>>(null);
  const save = useMutation({
    mutationFn: ({ row, payload }: { row: T | null; payload: Record<string, unknown> }) =>
      row ? api.put<T>(`${endpoint}/${row.id}`, payload) : api.post<T>(endpoint, payload),
    onSuccess: (saved, { row }) => {
      toast({
        tone: 'success',
        title: row ? 'Alterações salvas' : labels.created,
        description: resource.describe(saved),
      });
      setEditing(null);
      void invalidate();
    },
  });

  // --- Excluir ---------------------------------------------------------------------------------
  const [deleting, setDeleting] = useState<T | null>(null);
  const remove = useMutation({
    mutationFn: (row: T) => api.delete(`${endpoint}/${row.id}`),
    onSuccess: (_data, row) => {
      toast({ tone: 'success', title: 'Registro excluído', description: resource.describe(row) });
      setDeleting(null);
      void invalidate();
    },
  });

  // --- Restaurar -------------------------------------------------------------------------------
  const restore = useMutation({
    mutationFn: (row: T) => api.post<T>(`${endpoint}/${row.id}/restore`),
    onSuccess: (_data, row) => {
      toast({ tone: 'success', title: 'Registro restaurado', description: resource.describe(row) });
      void invalidate();
    },
    onError: (error, row) => {
      toast({
        tone: 'danger',
        title: `Não foi possível restaurar ${resource.describe(row)}`,
        description: errorMessage(error),
      });
    },
  });

  const columns = ((): DataTableColumn<T>[] => {
    const base: DataTableColumn<T>[] = resource.columns.map((column) => ({
      key: column.sortField ?? column.key,
      header: column.header,
      sortable: Boolean(column.sortField),
      className: column.className,
      cell: (row) =>
        column.key === 'is_active' && row.deleted_at ? (
          <Badge tone="danger">Excluído</Badge>
        ) : (
          column.cell(row)
        ),
    }));
    if (!canManage) return base;
    return [
      ...base,
      {
        key: 'actions',
        header: 'Ações',
        className: 'w-px',
        cell: (row) => {
          const name = resource.describe(row);
          return row.deleted_at ? (
            <IconButton
              label={`Restaurar ${name}`}
              icon={<RotateCcw className="size-5" />}
              onClick={() => restore.mutate(row)}
              disabled={restore.isPending}
            />
          ) : (
            <div className="flex gap-2">
              <IconButton
                label={`Editar ${name}`}
                icon={<Pencil className="size-5" />}
                onClick={() => setEditing({ row })}
              />
              <IconButton
                label={`Excluir ${name}`}
                icon={<Trash2 className="size-5" />}
                onClick={() => {
                  remove.reset();
                  setDeleting(row);
                }}
              />
            </div>
          );
        },
      },
    ];
  })();

  const hasFilters = Boolean(q) || filterNames.some((name) => params.get(name));

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={resource.title}
        description={resource.description}
        actions={
          canManage && (
            <Button onClick={() => setEditing({ row: null })}>
              <Plus aria-hidden="true" className="size-5" />
              {labels.create}
            </Button>
          )
        }
      />

      {/* Parâmetros da lista vivem na URL (deep-link): q, filtros, sort, page, with_trashed. */}
      <div
        role="search"
        aria-label={`Filtros de ${resource.title}`}
        className="flex flex-wrap items-end gap-4"
      >
        <FormField label="Buscar" className="w-full sm:w-72">
          <Input
            type="search"
            value={search}
            placeholder={resource.searchPlaceholder}
            onChange={(event) => setSearch(event.target.value)}
          />
        </FormField>
        {(resource.filters ?? []).map((filter) => (
          <FormField key={filter.name} label={filter.label} className="w-full sm:w-56">
            <Select
              value={params.get(filter.name) ?? ''}
              disabled={filter.loading}
              placeholder={filter.loading ? 'Carregando…' : (filter.allLabel ?? 'Todos')}
              options={filter.options}
              onChange={(event) => updateParams({ [filter.name]: event.target.value })}
            />
          </FormField>
        ))}
        {canManage && (
          <Switch
            label="Mostrar excluídos"
            checked={withTrashed}
            onCheckedChange={(checked) => updateParams({ with_trashed: checked ? '1' : null })}
          />
        )}
      </div>

      <DataTable
        caption={resource.title}
        columns={columns}
        rows={list.data?.data ?? []}
        rowKey={(row) => row.id}
        sort={sort}
        onSortChange={(next) => updateParams({ sort: formatSort(next) })}
        meta={list.data?.meta}
        onPageChange={(next) => updateParams({ page: String(next) })}
        loading={list.isPending}
        error={list.isError ? errorMessage(list.error) : null}
        onRetry={() => void list.refetch()}
        emptyTitle={hasFilters ? 'Nenhum resultado para os filtros' : 'Nenhum registro cadastrado'}
        emptyDescription={
          hasFilters
            ? 'Ajuste a busca ou os filtros.'
            : canManage
              ? `Use "${labels.create}".`
              : undefined
        }
      />

      {editing && (
        <CrudFormModal
          resource={resource}
          row={editing.row}
          open
          onOpenChange={(open) => !open && setEditing(null)}
          onSubmit={(payload) => save.mutateAsync({ row: editing.row, payload })}
        />
      )}

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && !remove.isPending && setDeleting(null)}
        title={`Excluir ${labels.singular}?`}
        description={
          deleting
            ? `${resource.describe(deleting)} deixará de aparecer nas listas. A exclusão pode ser desfeita em "Mostrar excluídos".`
            : ''
        }
        confirmLabel="Excluir"
        destructive
        loading={remove.isPending}
        error={remove.isError ? errorMessage(remove.error) : null}
        onConfirm={() => deleting && remove.mutate(deleting)}
      />
    </div>
  );
}
