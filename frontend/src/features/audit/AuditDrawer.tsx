import { useQuery } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { ApiError, GENERIC_ERROR_MESSAGE, api } from '@/api';
import { Badge, Button, Drawer, EmptyState, Skeleton } from '@/components/ui';
import { AuditDiff, AuditMetadata } from './AuditValues';
import { actorText, entityText } from './describe';
import { formatEventAt } from './format';
import { ACTION_LABELS } from './labels';
import type { AuditLog } from './types';

export const NOT_FOUND_MESSAGE = 'Evento de auditoria não encontrado.';

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section aria-label={title} className="flex flex-col gap-2">
      <h3 className="text-sm font-bold tracking-wide text-text-muted uppercase">{title}</h3>
      {children}
    </section>
  );
}

function Item({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex min-w-0 flex-col">
      <dt className="text-sm text-text-muted">{label}</dt>
      <dd className="break-words text-text">{children}</dd>
    </div>
  );
}

function Detail({ log }: { log: AuditLog }) {
  return (
    <div className="flex flex-col gap-6">
      <Section title="Evento">
        <dl className="grid gap-x-4 gap-y-2 rounded-card border border-border p-4 sm:grid-cols-2">
          <Item label="Data e hora">{formatEventAt(log.event_at)}</Item>
          <Item label="Ação">
            <Badge tone="info">{ACTION_LABELS[log.action]}</Badge>
          </Item>
          <Item label="Ator">{actorText(log.actor)}</Item>
          <Item label="Entidade">{entityText(log.auditable)}</Item>
          <Item label="IP">{log.ip ?? '—'}</Item>
          <Item label="Requisição">
            <span className="font-mono text-sm">{log.request_id ?? '—'}</span>
          </Item>
          <Item label="Identificador do evento">
            <span className="font-mono text-sm">{log.uuid}</span>
          </Item>
        </dl>
      </Section>
      <Section title="Alterações">
        <AuditDiff log={log} />
      </Section>
      <Section title="Metadados">
        <AuditMetadata metadata={log.metadata} />
      </Section>
    </div>
  );
}

/** Detalhe do evento (G.2-6) pelo `?id=` da lista; busca o evento (deep-link funciona sozinho). */
export function AuditDrawer({ id, onClose }: { id: number; onClose: () => void }) {
  const detail = useQuery({
    queryKey: ['/audit-logs', 'detail', id],
    queryFn: ({ signal }) => api.get<AuditLog>(`/audit-logs/${id}`, { signal }),
  });
  const log = detail.data;
  const notFound = detail.error instanceof ApiError && detail.error.status === 404;

  return (
    <Drawer
      open
      onOpenChange={(open) => !open && onClose()}
      title={
        log ? `${ACTION_LABELS[log.action]} · ${entityText(log.auditable)}` : 'Evento de auditoria'
      }
      description={log ? `${formatEventAt(log.event_at)} · ${actorText(log.actor)}` : undefined}
    >
      {detail.isPending ? (
        <div className="flex flex-col gap-4" aria-busy="true">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-24 w-full" />
          ))}
        </div>
      ) : detail.isError ? (
        <EmptyState
          role="alert"
          title={notFound ? 'Evento não encontrado' : 'Não foi possível carregar o evento'}
          description={
            notFound
              ? NOT_FOUND_MESSAGE
              : detail.error instanceof ApiError
                ? detail.error.message
                : GENERIC_ERROR_MESSAGE
          }
          action={
            !notFound && (
              <Button variant="secondary" onClick={() => void detail.refetch()}>
                Tentar novamente
              </Button>
            )
          }
        />
      ) : (
        <Detail log={detail.data} />
      )}
    </Drawer>
  );
}
