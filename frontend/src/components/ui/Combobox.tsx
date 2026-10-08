import { X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { cn } from '@/lib/cn';
import { TAP_MIN_CLASSES } from '@/lib/tokens';
import { IconButton } from './IconButton';
import { fieldBase, fieldBorder } from './styles';

export interface ComboboxOption {
  value: string;
  label: string;
}

export interface ComboboxProps {
  /** Busca remota (ex.: `?q=` da API). Chamada com debounce; ignora respostas obsoletas. */
  loadOptions: (query: string, signal: AbortSignal) => Promise<ComboboxOption[]>;
  value: ComboboxOption | null;
  onChange: (option: ComboboxOption | null) => void;
  debounceMs?: number;
  placeholder?: string;
  invalid?: boolean;
  disabled?: boolean;
  id?: string;
  'aria-describedby'?: string;
  'aria-required'?: boolean;
  'aria-label'?: string;
  className?: string;
}

type Status = 'idle' | 'loading' | 'error';

/** Combobox (WAI-ARIA 1.2, lista com autocomplete) com busca remota. */
export function Combobox({
  loadOptions,
  value,
  onChange,
  debounceMs = 300,
  placeholder,
  invalid,
  disabled,
  id,
  className,
  ...aria
}: ComboboxProps) {
  const autoId = useId();
  const inputId = id ?? autoId;
  const listId = `${inputId}-list`;
  const [open, setOpen] = useState(false);
  const [text, setText] = useState(value?.label ?? '');
  const [options, setOptions] = useState<ComboboxOption[]>([]);
  const [status, setStatus] = useState<Status>('idle');
  const [active, setActive] = useState(-1);
  const [query, setQuery] = useState('');
  const loadRef = useRef(loadOptions);
  useEffect(() => {
    loadRef.current = loadOptions;
  });

  // Mantém o texto sincronizado quando o valor muda por fora (ex.: reset do formulário).
  const valueLabel = value?.label ?? '';
  const [prevLabel, setPrevLabel] = useState(valueLabel);
  if (prevLabel !== valueLabel) {
    setPrevLabel(valueLabel);
    setText(valueLabel);
  }

  useEffect(() => {
    if (!open) return;
    const controller = new AbortController();
    // Primeira abertura (query vazia) busca já; digitação aguarda o debounce.
    const timer = setTimeout(
      () => {
        setStatus('loading');
        loadRef
          .current(query, controller.signal)
          .then((result) => {
            if (controller.signal.aborted) return;
            setOptions(result);
            setActive(result.length > 0 ? 0 : -1);
            setStatus('idle');
          })
          .catch(() => {
            if (controller.signal.aborted) return;
            setOptions([]);
            setStatus('error');
          });
      },
      query === '' ? 0 : debounceMs,
    );
    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [open, query, debounceMs]);

  function select(option: ComboboxOption) {
    onChange(option);
    setText(option.label);
    setOpen(false);
  }

  function onKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      if (!open) setOpen(true);
      else setActive((i) => Math.min(i + 1, options.length - 1));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActive((i) => Math.max(i - 1, 0));
    } else if (event.key === 'Enter' && open && active >= 0) {
      event.preventDefault();
      const option = options[active];
      if (option) select(option);
    } else if (event.key === 'Escape' && open) {
      event.preventDefault();
      setOpen(false);
      setText(valueLabel);
    }
  }

  const activeId = open && active >= 0 ? `${inputId}-opt-${active}` : undefined;

  return (
    <div className={cn('relative', className)}>
      <div className="flex items-center gap-2">
        <input
          id={inputId}
          role="combobox"
          type="text"
          autoComplete="off"
          aria-expanded={open}
          aria-controls={listId}
          aria-autocomplete="list"
          aria-activedescendant={activeId}
          aria-invalid={invalid || undefined}
          placeholder={placeholder}
          disabled={disabled}
          value={text}
          className={cn(TAP_MIN_CLASSES, fieldBase, fieldBorder(invalid))}
          onChange={(event) => {
            setText(event.target.value);
            setQuery(event.target.value);
            setOpen(true);
          }}
          onFocus={() => setOpen(true)}
          onBlur={() => {
            setOpen(false);
            setText(valueLabel);
          }}
          onKeyDown={onKeyDown}
          {...aria}
        />
        {value && !disabled && (
          <IconButton
            label="Limpar seleção"
            icon={<X className="size-5" />}
            onClick={() => {
              onChange(null);
              setText('');
              setQuery('');
            }}
          />
        )}
      </div>
      <ul
        id={listId}
        role="listbox"
        aria-label={aria['aria-label'] ?? 'Opções'}
        hidden={!open}
        className="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-border bg-surface shadow-lg"
      >
        {options.map((option, index) => (
          // Seleção por teclado é feita no input (aria-activedescendant); o clique é conveniência de mouse/touch.
          // eslint-disable-next-line jsx-a11y/click-events-have-key-events
          <li
            key={option.value}
            id={`${inputId}-opt-${index}`}
            role="option"
            aria-selected={value?.value === option.value}
            className={cn(
              'flex min-h-12 cursor-pointer items-center px-3',
              index === active && 'bg-surface-muted',
            )}
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => select(option)}
          >
            {option.label}
          </li>
        ))}
      </ul>
      {open && status !== 'idle' && (
        <p
          role={status === 'error' ? 'alert' : 'status'}
          className="absolute z-30 mt-1 w-full rounded-lg border border-border bg-surface p-3 text-ink-muted shadow-lg"
        >
          {status === 'loading' ? 'Carregando…' : 'Não foi possível buscar. Tente novamente.'}
        </p>
      )}
      {open && status === 'idle' && options.length === 0 && (
        <p
          role="status"
          className="absolute z-30 mt-1 w-full rounded-lg border border-border bg-surface p-3 text-ink-muted shadow-lg"
        >
          Nenhum resultado.
        </p>
      )}
    </div>
  );
}
