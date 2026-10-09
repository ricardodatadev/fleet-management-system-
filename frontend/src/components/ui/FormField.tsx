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
  /** Marca o campo como obrigatório: asterisco visual (aria-hidden) + `aria-required`. */
  required?: boolean;
  /**
   * Opt-out do asterisco, mantendo `aria-required`. Só nas telas públicas de autenticação (login,
   * esqueci e redefinir senha), por decisão do usuário (G.1); o resto do sistema mostra o `*`.
   */
  hideRequiredMark?: boolean;
  className?: string;
  /** Função (recebe as props de acessibilidade) ou elemento único (props injetadas). */
  children: ((props: FieldControlProps) => ReactNode) | ReactElement<Partial<FieldControlProps>>;
}

export function FormField({
  label,
  error,
  help,
  required,
  hideRequiredMark = false,
  className,
  children,
}: FormFieldProps) {
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
      <label htmlFor={id} className="font-bold text-text">
        {label}
        {required && !hideRequiredMark && (
          <span aria-hidden="true" className="text-danger">
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
        <p id={helpId} className="text-sm text-text-muted">
          {help}
        </p>
      )}
      {error && (
        <p id={errorId} className="text-sm font-bold text-danger">
          {error}
        </p>
      )}
    </div>
  );
}
