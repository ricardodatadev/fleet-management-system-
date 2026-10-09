import { X } from 'lucide-react';
import { Dialog } from 'radix-ui';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { useReturnFocus } from './focus';
import { IconButton } from './IconButton';

export interface OverlayPanelProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description?: string;
  children?: ReactNode;
  /** Rodapé fixo (botões de ação). */
  footer?: ReactNode;
  className?: string;
}

interface ShellProps extends OverlayPanelProps {
  panelClassName: string;
}

/** Casca compartilhada por Modal e Drawer: foco preso, ESC, foco devolvido (Radix Dialog). */
function DialogShell({
  open,
  onOpenChange,
  title,
  description,
  children,
  footer,
  className,
  panelClassName,
}: ShellProps) {
  const returnFocus = useReturnFocus();
  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 z-40 bg-overlay" />
        <Dialog.Content
          {...returnFocus}
          className={cn(
            'fixed z-50 flex flex-col bg-surface shadow-xl focus:outline-none',
            panelClassName,
            className,
          )}
        >
          <div className="flex items-start justify-between gap-2 border-b border-border p-4">
            <div className="flex flex-col gap-1">
              <Dialog.Title className="text-xl font-bold text-text">{title}</Dialog.Title>
              {description ? (
                <Dialog.Description className="text-text-muted">{description}</Dialog.Description>
              ) : (
                <Dialog.Description className="sr-only">{title}</Dialog.Description>
              )}
            </div>
            <Dialog.Close asChild>
              <IconButton label="Fechar" icon={<X className="size-6" />} />
            </Dialog.Close>
          </div>
          <div className="flex-1 overflow-y-auto p-4">{children}</div>
          {footer && (
            <div className="flex flex-wrap justify-end gap-2 border-t border-border p-4">
              {footer}
            </div>
          )}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}

export function Modal(props: OverlayPanelProps) {
  return (
    <DialogShell
      {...props}
      panelClassName="top-1/2 left-1/2 max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 rounded-card"
    />
  );
}

export interface DrawerProps extends OverlayPanelProps {
  /** Lado de entrada (padrão: direita; o menu mobile usa a esquerda). */
  side?: 'left' | 'right';
}

export function Drawer({ side = 'right', ...props }: DrawerProps) {
  return (
    <DialogShell
      {...props}
      panelClassName={cn(
        'top-0 h-full w-full',
        side === 'right' ? 'right-0 max-w-xl sm:rounded-l-card' : 'left-0 max-w-xs rounded-r-card',
      )}
    />
  );
}
