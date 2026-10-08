import { AlertDialog } from 'radix-ui';
import { Button } from './Button';
import { useReturnFocus } from './focus';

export interface ConfirmDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description: string;
  confirmLabel?: string;
  cancelLabel?: string;
  /** Chamado ao confirmar; o diálogo NÃO fecha sozinho (o chamador decide, ex.: após 409). */
  onConfirm: () => void;
  destructive?: boolean;
  loading?: boolean;
  /** Mensagem de erro do envelope (ex.: 409) exibida sem fechar o diálogo. */
  error?: string | null;
}

export function ConfirmDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel = 'Confirmar',
  cancelLabel = 'Cancelar',
  onConfirm,
  destructive = false,
  loading = false,
  error,
}: ConfirmDialogProps) {
  const returnFocus = useReturnFocus();
  return (
    <AlertDialog.Root open={open} onOpenChange={onOpenChange}>
      <AlertDialog.Portal>
        <AlertDialog.Overlay className="fixed inset-0 z-40 bg-black/50" />
        <AlertDialog.Content
          {...returnFocus}
          className="fixed top-1/2 left-1/2 z-50 flex w-[calc(100%-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 flex-col gap-4 rounded-xl bg-surface p-6 shadow-xl focus:outline-none"
        >
          <AlertDialog.Title className="text-xl font-bold text-ink">{title}</AlertDialog.Title>
          <AlertDialog.Description className="text-ink-muted">
            {description}
          </AlertDialog.Description>
          {error && (
            <p role="alert" className="font-medium text-danger-600">
              {error}
            </p>
          )}
          <div className="flex flex-wrap justify-end gap-2">
            <AlertDialog.Cancel asChild>
              <Button variant="secondary" disabled={loading}>
                {cancelLabel}
              </Button>
            </AlertDialog.Cancel>
            <Button
              variant={destructive ? 'danger' : 'primary'}
              loading={loading}
              onClick={onConfirm}
            >
              {confirmLabel}
            </Button>
          </div>
        </AlertDialog.Content>
      </AlertDialog.Portal>
    </AlertDialog.Root>
  );
}
