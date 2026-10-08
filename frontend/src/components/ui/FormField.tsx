import { cloneElement, isValidElement, useId } from 'react';
import type { ReactElement, ReactNode } from 'react';
import { cn } from '@/lib/cn';

export interface FieldControlProps {
  id: string;
  'aria-describedby'?: string;
  'aria-invalid'?: true;
  'aria-required'?: true;
}

export interface FormFieldProps {
  label: string;
  /** Mensagem de erro (ex.: do 422). Quando presente o controle recebe aria-invalid. */
  error?: string | null;
  help?: string;
  required?: boolean;
  className?: string;
  /** Função (recebe as props de acessibilidade) ou elemento único (props injetadas). */
  children: ((props: FieldControlProps) => ReactNode) | ReactElement<Partial<FieldControlProps>>;
}

export function FormField({ label, error, help, required, className, children }: FormFieldProps) {
  const id = useId();
  const helpId = help ? `${id}-help` : undefined;
  const errorId = error ? `${id}-error` : undefined;
  const describedBy = [helpId, errorId].filter(Boolean).join(' ') || undefined;

  const control: FieldControlProps = {
    id,
    'aria-describedby': describedBy,
    'aria-invalid': error ? true : undefined,
    'aria-required': required ? true : undefined,
  };

  return (
    <div className={cn('flex flex-col gap-2', className)}>
      <label htmlFor={id} className="font-semibold text-ink">
        {label}
        {required && (
          <span aria-hidden="true" className="text-danger-600">
            {' '}
            *
          </span>
        )}
      </label>
      {typeof children === 'function'
        ? children(control)
        : isValidElement(children)
          ? cloneElement(children, control)
          : null}
      {help && (
        <p id={helpId} className="text-sm text-ink-muted">
          {help}
        </p>
      )}
      {error && (
        <p id={errorId} className="text-sm font-medium text-danger-600">
          {error}
        </p>
      )}
    </div>
  );
}
