import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

/** Placeholder de carregamento (decorativo; o contêiner deve marcar aria-busy). */
export function Skeleton({ className, ...rest }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      aria-hidden="true"
      className={cn('h-4 animate-pulse rounded-control bg-surface-muted', className)}
      {...rest}
    />
  );
}
