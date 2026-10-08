import { Tabs as TabsPrimitive } from 'radix-ui';
import type { ComponentPropsWithoutRef, ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { TAP_MIN_CLASSES } from '@/lib/tokens';
import { Badge } from './Badge';
import { Tooltip } from './Tooltip';

export const Tabs = TabsPrimitive.Root;
export const TabsContent = TabsPrimitive.Content;

export function TabsList({
  className,
  ...rest
}: ComponentPropsWithoutRef<typeof TabsPrimitive.List>) {
  return (
    <TabsPrimitive.List
      className={cn('flex flex-wrap gap-2 border-b border-border', className)}
      {...rest}
    />
  );
}

const tabBase = cn(
  TAP_MIN_CLASSES,
  'inline-flex items-center gap-2 border-b-2 px-4 font-semibold whitespace-nowrap',
);

export interface TabsTriggerProps {
  value: string;
  children: ReactNode;
  /** Aba de fase futura: `aria-disabled`, selo "Em breve", sem ativação. */
  disabled?: boolean;
}

export function TabsTrigger({ value, children, disabled = false }: TabsTriggerProps) {
  if (disabled) {
    return (
      <Tooltip content="Disponível em fase futura">
        <button
          type="button"
          role="tab"
          aria-selected="false"
          aria-disabled="true"
          tabIndex={-1}
          className={cn(tabBase, 'cursor-not-allowed border-transparent text-ink-muted opacity-70')}
          onClick={(event) => event.preventDefault()}
        >
          {children}
          <Badge>Em breve</Badge>
        </button>
      </Tooltip>
    );
  }
  return (
    <TabsPrimitive.Trigger
      value={value}
      className={cn(
        tabBase,
        'border-transparent text-ink-muted hover:text-ink data-[state=active]:border-brand-600 data-[state=active]:text-brand-600',
      )}
    >
      {children}
    </TabsPrimitive.Trigger>
  );
}
