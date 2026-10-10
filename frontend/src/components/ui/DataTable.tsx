import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, ChevronsUpDown } from 'lucide-react';
import type { ReactNode } from 'react';
import type { PageMeta } from '@/api';
import { cn } from '@/lib/cn';
import { TAP_MIN_CLASSES } from '@/lib/tokens';
import { Button } from './Button';
import { EmptyState } from './EmptyState';
import { Skeleton } from './Skeleton';
import { nextSort } from './sort';
import type { SortState } from './sort';

export interface DataTableColumn<T> {
  key: string;
  header: string;
  /** Habilita ordenação server-side por `key`. */
  sortable?: boolean;
  cell: (row: T) => ReactNode;
  className?: string;
}

export interface DataTableProps<T> {
  /** Legenda acessível da tabela. */
  caption: string;
  columns: DataTableColumn<T>[];
  rows: T[];
  rowKey: (row: T) => string | number;
  sort?: SortState | null;
  onSortChange?: (sort: SortState | null) => void;
  meta?: PageMeta;
  onPageChange?: (page: number) => void;
  loading?: boolean;
  /** Mensagem de erro; mostra estado de erro com "Tentar novamente". */
  error?: string | null;
  onRetry?: () => void;
  emptyTitle?: string;
  emptyDescription?: string;
  /** Torna a linha selecionável (mouse) e o 1º campo um botão (teclado). */
  onRowClick?: (row: T) => void;
  skeletonRows?: number;
}

/** Controles dentro da célula têm ação própria e não devem abrir a linha. */
const INTERACTIVE =
  'button, a, input, select, textarea, label, [role=checkbox], [role=switch], [role=menuitem]';

function ariaSort(sort: SortState | null | undefined, key: string) {
  if (sort?.field !== key) return 'none';
  return sort.direction === 'asc' ? 'ascending' : 'descending';
}

export function DataTable<T>({
  caption,
  columns,
  rows,
  rowKey,
  sort,
  onSortChange,
  meta,
  onPageChange,
  loading = false,
  error,
  onRetry,
  emptyTitle = 'Nenhum registro encontrado',
  emptyDescription,
  onRowClick,
  skeletonRows = 5,
}: DataTableProps<T>) {
  const showRows = !loading && !error && rows.length > 0;
  // Com total > 0 a paginação aparece mesmo com a página vazia (ex.: excluiu o último item da
  // última página), senão o usuário fica sem como voltar.
  const showPagination = !loading && !error && meta !== undefined && meta.total > 0;

  return (
    <div className="flex flex-col gap-4">
      <div className="overflow-x-auto rounded-card border border-border bg-surface">
        <table className="w-full border-collapse text-left" aria-busy={loading || undefined}>
          <caption className="sr-only">{caption}</caption>
          <thead className="bg-surface-muted">
            <tr>
              {columns.map((column) => (
                <th
                  key={column.key}
                  scope="col"
                  aria-sort={column.sortable ? ariaSort(sort, column.key) : undefined}
                  className={cn('px-2 font-bold whitespace-nowrap', column.className)}
                >
                  {column.sortable ? (
                    <button
                      type="button"
                      className={cn(
                        TAP_MIN_CLASSES,
                        'inline-flex items-center gap-2 px-2 font-bold',
                      )}
                      onClick={() => onSortChange?.(nextSort(sort, column.key))}
                    >
                      {column.header}
                      {sort?.field === column.key ? (
                        sort.direction === 'asc' ? (
                          <ArrowUp aria-hidden="true" className="size-4" />
                        ) : (
                          <ArrowDown aria-hidden="true" className="size-4" />
                        )
                      ) : (
                        <ChevronsUpDown aria-hidden="true" className="size-4 text-text-muted" />
                      )}
                    </button>
                  ) : (
                    <span className="inline-flex min-h-12 items-center px-2">{column.header}</span>
                  )}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {loading &&
              Array.from({ length: skeletonRows }, (_, row) => (
                <tr key={row} className="border-t border-border" data-testid="skeleton-row">
                  {columns.map((column) => (
                    <td key={column.key} className="min-h-12 px-4 py-4">
                      <Skeleton className="h-5 w-full" />
                    </td>
                  ))}
                </tr>
              ))}
            {showRows &&
              rows.map((row) => (
                // O clique na linha é conveniência de mouse; o teclado usa o botão da 1ª coluna.
                <tr
                  key={rowKey(row)}
                  className={cn(
                    'border-t border-border hover:bg-surface-muted',
                    onRowClick && 'cursor-pointer',
                  )}
                  onClick={
                    onRowClick
                      ? (event) => {
                          const target = event.target as HTMLElement;
                          if (target.closest(INTERACTIVE)) return;
                          // Foca o botão da linha antes de abrir: o diálogo devolve o foco a ele.
                          event.currentTarget.querySelector<HTMLElement>('td button')?.focus();
                          onRowClick(row);
                        }
                      : undefined
                  }
                >
                  {columns.map((column, index) => (
                    <td key={column.key} className={cn('min-h-12 px-4 py-2', column.className)}>
                      {onRowClick && index === 0 ? (
                        <button
                          type="button"
                          className={cn(
                            TAP_MIN_CLASSES,
                            'text-left font-bold text-brand underline',
                          )}
                          onClick={(event) => {
                            event.stopPropagation();
                            onRowClick(row);
                          }}
                        >
                          {column.cell(row)}
                        </button>
                      ) : (
                        column.cell(row)
                      )}
                    </td>
                  ))}
                </tr>
              ))}
          </tbody>
        </table>
        {error && !loading && (
          <EmptyState
            role="alert"
            title="Não foi possível carregar os dados"
            description={error}
            action={
              onRetry && (
                <Button variant="secondary" onClick={onRetry}>
                  Tentar novamente
                </Button>
              )
            }
          />
        )}
        {!loading && !error && rows.length === 0 && (
          <EmptyState role="status" title={emptyTitle} description={emptyDescription} />
        )}
      </div>
      {showPagination && meta && (
        <nav aria-label="Paginação" className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-text-muted" aria-live="polite">
            Página {meta.current_page} de {meta.last_page} · {meta.total} registros
          </p>
          <div className="flex gap-2">
            <Button
              variant="secondary"
              disabled={meta.current_page <= 1}
              onClick={() => onPageChange?.(meta.current_page - 1)}
            >
              <ChevronLeft aria-hidden="true" className="size-5" />
              Anterior
            </Button>
            <Button
              variant="secondary"
              disabled={meta.current_page >= meta.last_page}
              onClick={() => onPageChange?.(meta.current_page + 1)}
            >
              Próxima
              <ChevronRight aria-hidden="true" className="size-5" />
            </Button>
          </div>
        </nav>
      )}
    </div>
  );
}
