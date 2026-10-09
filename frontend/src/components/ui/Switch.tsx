import { Switch as SwitchPrimitive } from 'radix-ui';
import { forwardRef, useId } from 'react';
import type { ComponentPropsWithoutRef, ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { TAP_MIN_CLASSES } from '@/lib/tokens';

export interface SwitchProps extends Omit<
  ComponentPropsWithoutRef<typeof SwitchPrimitive.Root>,
  'children'
> {
  /** Rótulo visível (clicável). Sem ele, passe aria-label. */
  label?: ReactNode;
}

export const Switch = forwardRef<HTMLButtonElement, SwitchProps>(function Switch(
  { label, id, className, ...rest },
  ref,
) {
  const autoId = useId();
  const switchId = id ?? autoId;
  return (
    <div className={cn('flex items-center gap-2', className)}>
      <SwitchPrimitive.Root
        ref={ref}
        id={switchId}
        className={cn(
          TAP_MIN_CLASSES,
          'group inline-flex min-w-14 shrink-0 items-center justify-center rounded-control bg-transparent disabled:cursor-not-allowed disabled:opacity-50',
        )}
        {...rest}
      >
        <span className="inline-flex h-7 w-12 items-center rounded-full bg-border-strong p-0.5 transition-colors group-data-[state=checked]:bg-brand">
          <SwitchPrimitive.Thumb className="block size-6 rounded-full bg-surface shadow transition-transform data-[state=checked]:translate-x-5" />
        </span>
      </SwitchPrimitive.Root>
      {label && (
        <label htmlFor={switchId} className="min-h-12 flex-1 content-center text-text">
          {label}
        </label>
      )}
    </div>
  );
});
