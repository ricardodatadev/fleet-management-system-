import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

export type BadgeTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

const tones: Record<BadgeTone, string> = {
  neutral: 'bg-gray-100 text-gray-800',
  info: 'bg-blue-100 text-blue-900',
  success: 'bg-green-100 text-green-900',
  warning: 'bg-yellow-100 text-yellow-900',
  danger: 'bg-red-100 text-red-900',
};

export interface BadgeProps extends HTMLAttributes<HTMLSpanElement> {
  tone?: BadgeTone;
}

/** Selo informativo (não interativo). */
export function Badge({ tone = 'neutral', className, ...rest }: BadgeProps) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap',
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
