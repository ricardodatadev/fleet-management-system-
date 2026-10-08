import { forwardRef } from 'react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { TAP_MIN_CLASSES } from '@/lib/tokens';
import { buttonBase, buttonVariants } from './styles';
import type { ButtonVariant } from './Button';

export interface IconButtonProps extends Omit<
  ButtonHTMLAttributes<HTMLButtonElement>,
  'aria-label' | 'children'
> {
  /** Nome acessível (obrigatório: o botão não tem texto visível). */
  label: string;
  icon: ReactNode;
  variant?: ButtonVariant;
}

export const IconButton = forwardRef<HTMLButtonElement, IconButtonProps>(function IconButton(
  { label, icon, variant = 'ghost', className, type = 'button', ...rest },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      aria-label={label}
      title={label}
      className={cn(TAP_MIN_CLASSES, buttonBase, 'px-0', buttonVariants[variant], className)}
      {...rest}
    >
      <span aria-hidden="true" className="inline-flex size-6 items-center justify-center">
        {icon}
      </span>
    </button>
  );
});
