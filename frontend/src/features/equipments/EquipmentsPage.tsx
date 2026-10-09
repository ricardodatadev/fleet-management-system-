import { Truck } from 'lucide-react';
import { EmptyState, PageHeader } from '@/components/ui';

/** Destino padrão após o login; a listagem real chega na F1-25. */
export function EquipmentsPage() {
  return (
    <div className="flex flex-col gap-6">
      <PageHeader title="Frotas & Equipamentos" />
      <EmptyState
        role="status"
        icon={<Truck className="size-12" />}
        title="Em construção"
        description="A listagem de equipamentos estará disponível em breve."
      />
    </div>
  );
}
