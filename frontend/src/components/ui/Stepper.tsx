import { Check } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Button } from './Button';

export interface StepperProps {
  steps: string[];
  /** Índice (0-based) do passo atual. */
  current: number;
  className?: string;
}

/** Indicador de progresso de passos (lista ordenada, `aria-current="step"`). */
export function Stepper({ steps, current, className }: StepperProps) {
  return (
    <ol className={cn('flex flex-wrap gap-4', className)} aria-label="Etapas">
      {steps.map((label, index) => {
        const done = index < current;
        const active = index === current;
        return (
          <li
            key={label}
            aria-current={active ? 'step' : undefined}
            className={cn(
              'flex items-center gap-2',
              active ? 'font-bold text-ink' : 'text-ink-muted',
            )}
          >
            <span
              aria-hidden="true"
              className={cn(
                'inline-flex size-8 items-center justify-center rounded-full border-2 text-sm',
                (done || active) && 'border-brand-600',
                done && 'bg-brand-600 text-white',
              )}
            >
              {done ? <Check className="size-4" /> : index + 1}
            </span>
            <span>{label}</span>
            {done && <span className="sr-only">(concluída)</span>}
          </li>
        );
      })}
    </ol>
  );
}

export interface WizardStep {
  label: string;
  content: ReactNode;
}

export interface WizardProps {
  steps: WizardStep[];
  /** Chamado no "Concluir" do último passo. */
  onFinish: () => void;
  /** Impede avançar (ex.: validação do passo atual). */
  canAdvance?: (index: number) => boolean;
  finishLabel?: string;
  finishing?: boolean;
}

/** Wizard simples: Stepper + Voltar/Avançar/Concluir. */
export function Wizard({
  steps,
  onFinish,
  canAdvance,
  finishLabel = 'Concluir',
  finishing = false,
}: WizardProps) {
  const [index, setIndex] = useState(0);
  const last = index === steps.length - 1;
  const step = steps[index];
  const allowed = canAdvance ? canAdvance(index) : true;

  return (
    <div className="flex flex-col gap-4">
      <Stepper steps={steps.map((s) => s.label)} current={index} />
      <div>{step?.content}</div>
      <div className="flex justify-between gap-2">
        <Button variant="secondary" disabled={index === 0} onClick={() => setIndex(index - 1)}>
          Voltar
        </Button>
        {last ? (
          <Button loading={finishing} disabled={!allowed} onClick={onFinish}>
            {finishLabel}
          </Button>
        ) : (
          <Button disabled={!allowed} onClick={() => setIndex(index + 1)}>
            Avançar
          </Button>
        )}
      </div>
    </div>
  );
}
