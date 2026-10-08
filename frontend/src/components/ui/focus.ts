import { useRef } from 'react';

/**
 * Devolve o foco ao elemento que estava focado quando o diálogo abriu.
 * Necessário porque Modal/Drawer/ConfirmDialog são controlados (sem `Dialog.Trigger`),
 * e sem gatilho o Radix não sabe para onde devolver o foco.
 */
export function useReturnFocus() {
  const ref = useRef<HTMLElement | null>(null);
  return {
    onOpenAutoFocus: () => {
      ref.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    },
    onCloseAutoFocus: (event: Event) => {
      const target = ref.current;
      ref.current = null;
      if (target?.isConnected) {
        event.preventDefault();
        target.focus();
      }
    },
  };
}
