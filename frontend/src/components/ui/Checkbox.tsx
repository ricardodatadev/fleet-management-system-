import { Check } from 'lucide-react';
import { Checkbox as CheckboxPrimitive } from 'radix-ui';
import { forwardRef, useId } from 'react';
import type { ComponentPropsWithoutRef, ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { TAP_MIN_CLASSES } from '@/lib/tokens';

export interface CheckboxProps extends Omit<
  ComponentPropsWithoutRef<typeof CheckboxPrimitive.Root>,
  'children'
> {
  label?: ReactNode;
}

export const Checkbox = forwardRef<HTMLButtonElement, CheckboxProps>(function Checkbox(
  { label, id, className, ...rest },
  ref,
) {
  const autoId = useId();
  const checkboxId = id ?? autoId;
  return (
    <div className={cn('flex items-center gap-2', className)}>
      <CheckboxPrimitive.Root
        ref={ref}
        id={checkboxId}
        className={cn(
          TAP_MIN_CLASSES,
          'group inline-flex shrink-0 items-center justify-center rounded-control bg-transparent disabled:cursor-not-allowed disabled:opacity-50',
        )}
        {...rest}
      >
        <span className="inline-flex size-6 items-center justify-center rounded-control border-2 border-border-strong bg-surface text-on-brand group-data-[state=checked]:border-brand group-data-[state=checked]:bg-brand group-data-[state=indeterminate]:border-brand group-data-[state=indeterminate]:bg-brand">
          <CheckboxPrimitive.Indicator>
            <Check aria-hidden="true" className="size-4" strokeWidth={3} />
          </CheckboxPrimitive.Indicator>
        </span>
      </CheckboxPrimitive.Root>
      {label && (
        <label htmlFor={checkboxId} className="min-h-12 flex-1 content-center text-text">
          {label}
        </label>
      )}
    </div>
  );
});
