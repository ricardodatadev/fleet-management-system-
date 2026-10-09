import type { ButtonVariant } from './Button';

export const buttonBase =
  'inline-flex items-center justify-center gap-2 rounded-control px-4 font-bold transition-colors disabled:cursor-not-allowed disabled:opacity-50 aria-disabled:cursor-not-allowed aria-disabled:opacity-50';

export const buttonVariants: Record<ButtonVariant, string> = {
  primary: 'bg-brand text-on-brand hover:bg-brand-hover active:bg-brand-pressed',
  secondary: 'border border-border bg-surface text-text hover:bg-surface-muted',
  danger: 'bg-danger text-on-danger hover:bg-danger-hover',
  ghost: 'bg-transparent text-brand hover:bg-surface-muted',
};

export const fieldBase =
  'w-full rounded-control border bg-surface px-3 text-base text-text placeholder:text-text-muted disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70';

export function fieldBorder(invalid?: boolean): string {
  // Contorno de campo: border-strong (>= 3:1, WCAG 1.4.11); a borda clara fica para divisórias.
  return invalid ? 'border-danger' : 'border-border-strong';
}
