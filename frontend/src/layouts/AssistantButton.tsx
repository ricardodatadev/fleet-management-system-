import { Tooltip } from '@/components/ui';
import { buttonBase } from '@/components/ui/styles';
import { cn } from '@/lib/cn';
import { TAP_MIN_CLASSES } from '@/lib/tokens';
import { ASSISTANT_ICON as AssistantIcon } from './navigation';

/** Assistente Virtual (G.3): botão flutuante desabilitado nesta fase. */
export function AssistantButton() {
  return (
    <Tooltip content="Disponível em fase futura" side="left">
      <button
        type="button"
        aria-disabled="true"
        aria-label="Assistente Virtual IA (em breve)"
        className={cn(
          TAP_MIN_CLASSES,
          buttonBase,
          'fixed right-4 bottom-4 z-30 size-14 rounded-full bg-surface-muted px-0 text-text-muted shadow-lg',
        )}
        onClick={(event) => event.preventDefault()}
      >
        <AssistantIcon aria-hidden="true" className="size-7" />
      </button>
    </Tooltip>
  );
}
