import { useQuery } from '@tanstack/react-query';
import { Pencil } from 'lucide-react';
import type { ReactNode } from 'react';
import { ApiError, GENERIC_ERROR_MESSAGE, api } from '@/api';
import {
  Badge,
  Button,
  Drawer,
  EmptyState,
  Skeleton,
  StatusPill,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from '@/components/ui';
import { CRITICALITY_LABELS } from '@/features/cadastros/labels';
import { cn } from '@/lib/cn';
import { formatDate } from './format';
import type { Equipment } from './types';

export const NOT_FOUND_MESSAGE =
  'Equipamento não encontrado. Ele pode ter sido excluído ou pertencer a outra filial.';

const SOURCE_LABELS: Record<Equipment['criticality_source'], string> = {
  family: 'Herdada da família',
  override: 'Definida no equipamento',
};

const money = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const meter = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 });
const dateTime = new Intl.DateTimeFormat('pt-BR', {
  dateStyle: 'short',
  timeStyle: 'short',
  timeZone: 'America/Sao_Paulo',
});

const dash = (value: ReactNode) =>
  value === null || value === undefined || value === '' ? '—' : value;
const ref = (item: { code: string; name: string } | null) =>
  item ? `${item.code} — ${item.name}` : '—';

function Group({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section aria-label={title} className="flex flex-col gap-2">
      <h3 className="text-sm font-bold tracking-wide text-text-muted uppercase">{title}</h3>
      <dl className="grid gap-x-4 gap-y-2 rounded-card border border-border p-4 sm:grid-cols-2">
        {children}
      </dl>
    </section>
  );
}

function Item({
  label,
  children,
  className,
}: {
  label: string;
  children: ReactNode;
  className?: string;
}) {
  // Um único div por par dt/dd: é o único wrapper que o <dl> aceita.
  return (
    <div className={cn('flex flex-col', className)}>
      <dt className="text-sm text-text-muted">{label}</dt>
      <dd className="text-text">{children}</dd>
    </div>
  );
}

function Overview({ equipment: e }: { equipment: Equipment }) {
  return (
    <div className="flex flex-col gap-6">
      <Group title="Identificação">
        <Item label="Código">{e.code}</Item>
        <Item label="Nome">{e.name}</Item>
        <Item label="Placa">{dash(e.plate)}</Item>
        <Item label="Nº de série">{dash(e.serial_number)}</Item>
        <Item label="Fabricante">{dash(e.manufacturer)}</Item>
        <Item label="Modelo">{dash(e.model)}</Item>
        <Item label="Ano">{dash(e.year)}</Item>
        <Item label="Status">
          <StatusPill status={e.status} />
        </Item>
      </Group>
      <Group title="Classe e criticidade">
        <Item label="Família/Classe">{ref(e.family)}</Item>
        <Item label="Criticidade efetiva">
          <span className="inline-flex flex-wrap items-center gap-2">
            {CRITICALITY_LABELS[e.criticality]}
            <Badge tone={e.criticality_source === 'override' ? 'info' : 'neutral'}>
              {SOURCE_LABELS[e.criticality_source]}
            </Badge>
          </span>
        </Item>
      </Group>
      <Group title="Alocação">
        <Item label="Filial">{ref(e.branch)}</Item>
        <Item label="Centro de custo">{ref(e.cost_center)}</Item>
        <Item label="Responsável">
          {e.responsible_employee
            ? `${e.responsible_employee.name} (${e.responsible_employee.registration})`
            : '—'}
        </Item>
      </Group>
      <Group title="Aquisição e medidores">
        <Item label="Data de aquisição">{formatDate(e.acquisition_date)}</Item>
        <Item label="Valor de aquisição">
          {e.acquisition_value === null ? '—' : money.format(e.acquisition_value)}
        </Item>
        <Item label="Odômetro">{`${meter.format(e.odometer_km)} km`}</Item>
        <Item label="Horímetro">{`${meter.format(e.hour_meter)} h`}</Item>
      </Group>
      <Group title="Observações e registro">
        <Item label="Observações" className="sm:col-span-2">
          <span className="whitespace-pre-line">{dash(e.notes)}</span>
        </Item>
        <Item label="Cadastrado em">
          {e.created_at ? dateTime.format(new Date(e.created_at)) : '—'}
        </Item>
        <Item label="Atualizado em">
          {e.updated_at ? dateTime.format(new Date(e.updated_at)) : '—'}
        </Item>
      </Group>
    </div>
  );
}

export interface EquipmentDrawerProps {
  id: number;
  onClose: () => void;
  /** Atalho para o formulário (F1-26); só é passado com equipments.manage. */
  onEdit?: (equipment: Equipment) => void;
}

/**
 * Drawer 360° do equipamento (G.4-2, F1-27): Visão Geral com todos os campos; Histórico OS, Pneus
 * e Documentos ficam para fases futuras. 404 (excluído ou de outra filial) vira mensagem clara.
 */
export function EquipmentDrawer({ id, onClose, onEdit }: EquipmentDrawerProps) {
  const detail = useQuery({
    queryKey: ['/equipments', 'detail', id],
    queryFn: ({ signal }) => api.get<Equipment>(`/equipments/${id}`, { signal }),
  });
  const equipment = detail.data;
  const notFound = detail.error instanceof ApiError && detail.error.status === 404;

  return (
    <Drawer
      open
      onOpenChange={(open) => !open && onClose()}
      title={equipment ? `${equipment.code} — ${equipment.name}` : 'Equipamento'}
      description={equipment ? `${equipment.family.name} · ${equipment.branch.name}` : undefined}
      // Fechar fica só no X do cabeçalho (um único controle com esse nome).
      footer={
        onEdit && equipment ? (
          <Button variant="secondary" onClick={() => onEdit(equipment)}>
            <Pencil aria-hidden="true" className="size-5" />
            Editar
          </Button>
        ) : undefined
      }
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
          title={
            notFound ? 'Equipamento não encontrado' : 'Não foi possível carregar o equipamento'
          }
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
        <Tabs defaultValue="visao-geral" className="flex flex-col gap-4">
          <TabsList aria-label="Seções do equipamento">
            <TabsTrigger value="visao-geral">Visão Geral</TabsTrigger>
            <TabsTrigger value="historico" disabled>
              Histórico OS
            </TabsTrigger>
            <TabsTrigger value="pneus" disabled>
              Pneus
            </TabsTrigger>
            <TabsTrigger value="documentos" disabled>
              Documentos
            </TabsTrigger>
          </TabsList>
          <TabsContent value="visao-geral">
            <Overview equipment={detail.data} />
          </TabsContent>
        </Tabs>
      )}
    </Drawer>
  );
}
