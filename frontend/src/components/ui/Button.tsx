import { Loader2 } from 'lucide-react';
import { forwardRef } from 'react';
import type { ButtonHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';
import { TAP_MIN_CLASSES } from '@/lib/tokens';
import { buttonBase, buttonVariants } from './styles';

export type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'ghost' | 'topbar';

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  /** Mostra spinner, bloqueia cliques e marca aria-busy. */
  loading?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'primary', loading = false, className, children, disabled, type = 'button', ...rest },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={cn(TAP_MIN_CLASSES, buttonBase, buttonVariants[variant], className)}
      {...rest}
    >
      {loading && <Loader2 aria-hidden="true" className="size-5 animate-spin" />}
      {children}
    </button>
  );
});
