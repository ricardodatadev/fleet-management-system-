import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

export type BadgeTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

const tones: Record<BadgeTone, string> = {
  neutral: 'bg-surface-muted text-text-muted',
  info: 'bg-info-subtle text-info',
  success: 'bg-success-subtle text-success',
  warning: 'bg-warning-subtle text-warning',
  danger: 'bg-danger-subtle text-danger',
};

export interface BadgeProps extends HTMLAttributes<HTMLSpanElement> {
  tone?: BadgeTone;
}

/** Selo informativo (não interativo). */
export function Badge({ tone = 'neutral', className, ...rest }: BadgeProps) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold whitespace-nowrap',
        tones[tone],
        className,
      )}
      {...rest}
    />
  );
}

/** Valores de `status` das entidades (spec C.6). */
export type EntityStatus = 'active' | 'inactive' | 'disposed';

const statusMap: Record<EntityStatus, { tone: BadgeTone; label: string }> = {
  active: { tone: 'success', label: 'Ativo' },
  inactive: { tone: 'neutral', label: 'Inativo' },
  disposed: { tone: 'danger', label: 'Baixado' },
};

export interface StatusPillProps {
  status: EntityStatus;
  /** Sobrescreve o rótulo padrão (pt-BR). */
  label?: string;
}

/** Status de entidade: cor + texto (nunca só cor). */
export function StatusPill({ status, label }: StatusPillProps) {
  const { tone, label: defaultLabel } = statusMap[status];
  return <Badge tone={tone}>{label ?? defaultLabel}</Badge>;
}
