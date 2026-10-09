import { RadioGroup as RadioPrimitive } from 'radix-ui';
import { forwardRef, useId } from 'react';
import type { ComponentPropsWithoutRef, ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { TAP_MIN_CLASSES } from '@/lib/tokens';

export interface RadioOption {
  value: string;
  label: ReactNode;
  disabled?: boolean;
}

export interface RadioGroupProps extends Omit<
  ComponentPropsWithoutRef<typeof RadioPrimitive.Root>,
  'children'
> {
  options: RadioOption[];
}

export const RadioGroup = forwardRef<HTMLDivElement, RadioGroupProps>(function RadioGroup(
  { options, className, ...rest },
  ref,
) {
  const groupId = useId();
  return (
    <RadioPrimitive.Root ref={ref} className={cn('flex flex-col gap-2', className)} {...rest}>
      {options.map((option) => {
        const id = `${groupId}-${option.value}`;
        return (
          <div key={option.value} className="flex items-center gap-2">
            <RadioPrimitive.Item
              id={id}
              value={option.value}
              disabled={option.disabled}
              className={cn(
                TAP_MIN_CLASSES,
                'group inline-flex shrink-0 items-center justify-center rounded-control bg-transparent disabled:cursor-not-allowed disabled:opacity-50',
              )}
            >
              <span className="inline-flex size-6 items-center justify-center rounded-full border-2 border-border-strong bg-surface group-data-[state=checked]:border-brand">
                <RadioPrimitive.Indicator className="block size-3 rounded-full bg-brand" />
              </span>
            </RadioPrimitive.Item>
            <label htmlFor={id} className="min-h-12 flex-1 content-center text-text">
              {option.label}
            </label>
          </div>
        );
      })}
    </RadioPrimitive.Root>
  );
});
