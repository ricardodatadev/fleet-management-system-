import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

export interface EmptyStateProps {
  title: string;
  description?: string;
  icon?: ReactNode;
  action?: ReactNode;
  /** `alert` para estados de erro (anunciado imediatamente). */
  role?: 'status' | 'alert';
  className?: string;
}

export function EmptyState({ title, description, icon, action, role, className }: EmptyStateProps) {
  return (
    <div
      role={role}
      className={cn('flex flex-col items-center gap-2 px-4 py-12 text-center', className)}
    >
      {icon && (
        <div aria-hidden="true" className="text-ink-muted">
          {icon}
        </div>
      )}
      <h2 className="text-lg font-semibold text-ink">{title}</h2>
      {description && <p className="max-w-prose text-ink-muted">{description}</p>}
      {action && <div className="mt-2">{action}</div>}
    </div>
  );
}
