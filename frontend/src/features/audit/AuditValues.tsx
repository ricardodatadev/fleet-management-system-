import { cn } from '@/lib/cn';
import { diffRows, isStructured, jsonText, scalarText } from './diff';
import { FIELD_LABELS, labelFor } from './labels';
import type { AuditLog } from './types';

/** Um valor do diff: JSON formatado para objeto/array; `nulo`/`vazio` em itálico (nunca some). */
export function AuditValue({ value }: { value: unknown }) {
  if (isStructured(value)) {
    return (
      <pre className="font-mono text-sm break-all whitespace-pre-wrap text-text">
        {jsonText(value)}
      </pre>
    );
  }
  const special = value === null || value === undefined || value === '';
  return (
    <span className={cn('break-words', special ? 'text-text-muted italic' : 'text-text')}>
      {scalarText(value)}
    </span>
  );
}

function Side({
  label,
  present,
  value,
  className,
}: {
  label: string;
  present: boolean;
  value: unknown;
  className: string;
}) {
  return (
    <div className={cn('flex min-w-0 flex-col gap-1 rounded-card p-2', className)}>
      <dt className="text-sm font-bold text-text">{label}</dt>
      <dd>
        {present ? (
          <AuditValue value={value} />
        ) : (
          <span className="text-text-muted">
            <span aria-hidden="true">—</span>
            <span className="sr-only">não se aplica</span>
          </span>
        )}
      </dd>
    </div>
  );
}

/** Nome do campo: rótulo pt-BR + nome técnico (o auditor precisa do nome exato). */
export function FieldName({ field }: { field: string }) {
  const label = labelFor(FIELD_LABELS, field);
  return (
    <span className="flex flex-wrap items-baseline gap-2">
      <span className="font-bold text-text">{label}</span>
      {label !== field && <code className="font-mono text-sm text-text-muted">{field}</code>}
    </span>
  );
}

/**
 * Diff old/new legível (G.2-6): um cartão por campo com "Antes" e "Depois". Os rótulos dizem o
 * lado (a cor é só reforço); lado inexistente (ex.: criação não tem "antes") aparece como "—".
 */
export function AuditDiff({ log }: { log: Pick<AuditLog, 'old_values' | 'new_values'> }) {
  const rows = diffRows(log);
  if (rows.length === 0) {
    return <p className="text-text-muted">Sem valores registrados neste evento.</p>;
  }
  return (
    <ul aria-label="Alterações" className="flex flex-col gap-2">
      {rows.map((row) => (
        <li key={row.field} className="flex flex-col gap-2 rounded-card border border-border p-2">
          <FieldName field={row.field} />
          <dl className="grid gap-2 sm:grid-cols-2">
            <Side
              label="Antes"
              present={row.hasBefore}
              value={row.before}
              className="bg-danger-subtle"
            />
            <Side
              label="Depois"
              present={row.hasAfter}
              value={row.after}
              className="bg-success-subtle"
            />
          </dl>
        </li>
      ))}
    </ul>
  );
}

/** Metadados do evento (ex.: escopo do parâmetro, motivo) como pares campo → valor. */
export function AuditMetadata({ metadata }: { metadata: AuditLog['metadata'] }) {
  const entries = Object.entries(metadata ?? {});
  if (entries.length === 0) return <p className="text-text-muted">Sem metadados.</p>;
  return (
    <dl className="flex flex-col gap-2 rounded-card border border-border p-4">
      {entries.map(([key, value]) => (
        <div key={key} className="flex flex-col gap-1">
          <dt>
            <FieldName field={key} />
          </dt>
          <dd>
            <AuditValue value={value} />
          </dd>
        </div>
      ))}
    </dl>
  );
}
