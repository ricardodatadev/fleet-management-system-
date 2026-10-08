import { X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { IconButton } from './IconButton';
import { ToastContext } from './toast-context';
import type { ToastApi, ToastOptions, ToastTone } from './toast-context';

interface ToastItem extends ToastOptions {
  id: string;
}

const toneClasses: Record<ToastTone, string> = {
  info: 'border-blue-700 bg-blue-50 text-blue-950',
  success: 'border-green-700 bg-green-50 text-green-950',
  warning: 'border-yellow-700 bg-yellow-50 text-yellow-950',
  danger: 'border-red-700 bg-red-50 text-red-950',
};

function ToastView({ item, onDismiss }: { item: ToastItem; onDismiss: (id: string) => void }) {
  const { id, tone = 'info', title, description, duration = 5000 } = item;

  useEffect(() => {
    if (duration <= 0) return;
    const timer = setTimeout(() => onDismiss(id), duration);
    return () => clearTimeout(timer);
  }, [id, duration, onDismiss]);

  return (
    <div
      role={tone === 'danger' ? 'alert' : 'status'}
      className={cn(
        'flex items-start gap-2 rounded-lg border-l-4 p-3 pl-4 shadow-lg',
        toneClasses[tone],
      )}
    >
      <div className="flex-1">
        <p className="font-semibold">{title}</p>
        {description && <p className="text-sm">{description}</p>}
      </div>
      <IconButton
        label="Fechar notificação"
        icon={<X className="size-5" />}
        onClick={() => onDismiss(id)}
      />
    </div>
  );
}

/** Provedor + região `aria-live` das notificações (canto inferior direito). */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  const counter = useRef(0);

  const dismiss = useCallback((id: string) => {
    setItems((current) => current.filter((item) => item.id !== id));
  }, []);

  const toast = useCallback((options: ToastOptions) => {
    counter.current += 1;
    const id = `toast-${counter.current}`;
    setItems((current) => [...current, { ...options, id }]);
    return id;
  }, []);

  const api = useMemo<ToastApi>(() => ({ toast, dismiss }), [toast, dismiss]);

  return (
    <ToastContext.Provider value={api}>
      {children}
      <div
        role="region"
        aria-label="Notificações"
        aria-live="polite"
        className="fixed right-4 bottom-4 z-[60] flex w-[calc(100%-2rem)] max-w-sm flex-col gap-2"
      >
        {items.map((item) => (
          <ToastView key={item.id} item={item} onDismiss={dismiss} />
        ))}
      </div>
    </ToastContext.Provider>
  );
}
