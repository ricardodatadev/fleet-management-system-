import type { ButtonVariant } from './Button';

export const buttonBase =
  'inline-flex items-center justify-center gap-2 rounded-lg px-4 font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50 aria-disabled:cursor-not-allowed aria-disabled:opacity-50';

export const buttonVariants: Record<ButtonVariant, string> = {
  primary: 'bg-brand-600 text-white hover:bg-brand-700',
  secondary: 'border border-border bg-surface text-ink hover:bg-surface-muted',
  danger: 'bg-danger-600 text-white hover:bg-red-800',
  ghost: 'bg-transparent text-brand-600 hover:bg-surface-muted',
};

export const fieldBase =
  'w-full rounded-lg border bg-surface px-3 text-base text-ink placeholder:text-gray-500 disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70';

export function fieldBorder(invalid?: boolean): string {
  return invalid ? 'border-danger-600' : 'border-border';
}
