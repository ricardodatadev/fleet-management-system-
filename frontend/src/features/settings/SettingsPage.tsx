import { SlidersHorizontal } from 'lucide-react';
import { EmptyState, PageHeader } from '@/components/ui';

/** Rota protegida por settings.view; o painel real chega na F1-28. */
export function SettingsPage() {
  return (
    <div className="flex flex-col gap-6">
      <PageHeader title="Painel de Parâmetros" />
      <EmptyState
        role="status"
        icon={<SlidersHorizontal className="size-12" />}
        title="Em construção"
        description="O painel de parâmetros estará disponível em breve."
      />
    </div>
  );
}
