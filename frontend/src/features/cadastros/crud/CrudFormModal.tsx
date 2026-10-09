import { useState } from 'react';
import type { FormEvent } from 'react';
import { ApiError, GENERIC_ERROR_MESSAGE } from '@/api';
import { Button, FormField, Input, Modal, Select, Switch } from '@/components/ui';
import type { SelectOption } from '@/components/ui';
import { PasswordInput } from '@/features/auth/PasswordInput';
import {
  initialValues,
  isRequired,
  isVisible,
  splitFieldErrors,
  toPayload,
  validate,
} from './form';
import type { CrudField, CrudResource, CrudRow, FormValues } from './types';

export interface CrudFormModalProps<T extends CrudRow> {
  resource: CrudResource<T>;
  /** `null` = criação. */
  row: T | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Grava (POST/PUT); erros `ApiError` são mapeados aqui. */
  onSubmit: (payload: Record<string, unknown>) => Promise<unknown>;
}

function selectOptions<T extends CrudRow>(
  field: CrudField<T>,
  row: T | null,
  values: FormValues,
  value: string,
) {
  if (field.kind !== 'select') return [];
  const options = typeof field.options === 'function' ? field.options(values) : field.options;
  const current = row && field.currentOption?.(row);
  const missing = current && value !== '' && !options.some((o) => o.value === value);
  return missing ? [...options, current as SelectOption] : options;
}

/** Formulário de criar/editar em Modal, gerado dos `fields` do recurso. */
export function CrudFormModal<T extends CrudRow>({
  resource,
  row,
  open,
  onOpenChange,
  onSubmit,
}: CrudFormModalProps<T>) {
  const [values, setValues] = useState<FormValues>(() => initialValues(resource.fields, row));
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const formId = `crud-form-${resource.endpoint.replace(/\W/g, '')}`;

  function setValue(name: string, value: string | boolean) {
    setValues((current) => {
      const next = { ...current, [name]: value };
      // Campos dependentes (ex.: centro de custo da filial) são limpos quando o pai muda.
      for (const field of resource.fields) {
        if (field.dependsOn?.includes(name)) next[field.name] = '';
      }
      return next;
    });
    setFieldErrors((current) => {
      const next = { ...current };
      delete next[name];
      return next;
    });
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setFormError(null);
    const clientErrors = splitFieldErrors(resource.fields, validate(resource.fields, values, row));
    if (Object.keys(clientErrors.byField).length > 0) {
      setFieldErrors(clientErrors.byField);
      return;
    }
    setSaving(true);
    try {
      await onSubmit(toPayload(resource.fields, values, row));
    } catch (error) {
      if (error instanceof ApiError && error.isValidation) {
        const { byField, other } = splitFieldErrors(resource.fields, error.errors);
        setFieldErrors(byField);
        setFormError(other.length > 0 ? other.join(' ') : error.message);
      } else {
        setFormError(error instanceof ApiError ? error.message : GENERIC_ERROR_MESSAGE);
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open={open}
      onOpenChange={(next) => !saving && onOpenChange(next)}
      title={row ? resource.labels.edit : resource.labels.create}
      description={row ? resource.describe(row) : undefined}
      footer={
        <>
          <Button variant="secondary" disabled={saving} onClick={() => onOpenChange(false)}>
            Cancelar
          </Button>
          <Button type="submit" form={formId} loading={saving}>
            Salvar
          </Button>
        </>
      }
    >
      <form id={formId} noValidate onSubmit={handleSubmit} className="flex flex-col gap-4">
        {formError && (
          <p role="alert" className="rounded-control bg-danger-subtle p-3 font-bold text-danger">
            {formError}
          </p>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          {resource.fields.map((field) => {
            if (!isVisible(field, values)) return null;
            const value = values[field.name];
            const error = fieldErrors[field.name];
            const lockReason = field.locked?.(row) ?? null;
            if (field.kind === 'switch') {
              return (
                <div key={field.name} className="flex flex-col justify-end gap-2">
                  <Switch
                    label={field.label}
                    checked={Boolean(value)}
                    disabled={Boolean(lockReason)}
                    onCheckedChange={(checked) => setValue(field.name, checked)}
                  />
                  {lockReason && <p className="text-sm text-text-muted">{lockReason}</p>}
                  {error && <p className="text-sm font-bold text-danger">{error}</p>}
                </div>
              );
            }
            const text = typeof value === 'string' ? value : '';
            return (
              <FormField
                key={field.name}
                label={field.label}
                required={isRequired(field, { row, values })}
                help={lockReason ?? field.help}
                error={error}
              >
                {(control) =>
                  field.kind === 'select' ? (
                    <Select
                      {...control}
                      invalid={Boolean(error)}
                      value={text}
                      disabled={field.loading || Boolean(lockReason)}
                      placeholder={
                        field.loading ? 'Carregando…' : (field.placeholder ?? 'Selecione')
                      }
                      options={selectOptions(field, row, values, text)}
                      onChange={(event) => setValue(field.name, event.target.value)}
                    />
                  ) : field.kind === 'password' ? (
                    <PasswordInput
                      {...control}
                      invalid={Boolean(error)}
                      value={text}
                      autoComplete={field.autoComplete ?? 'new-password'}
                      onChange={(event) => setValue(field.name, event.target.value)}
                    />
                  ) : (
                    <Input
                      {...control}
                      invalid={Boolean(error)}
                      value={text}
                      disabled={Boolean(lockReason)}
                      autoComplete={
                        field.kind === 'text' || field.kind === 'date'
                          ? field.autoComplete
                          : undefined
                      }
                      type={
                        field.kind === 'number' ? 'number' : field.kind === 'date' ? 'date' : 'text'
                      }
                      inputMode={field.kind === 'number' ? 'decimal' : undefined}
                      maxLength={field.kind === 'text' ? field.maxLength : undefined}
                      min={field.kind === 'number' ? field.min : undefined}
                      max={field.kind === 'number' ? field.max : undefined}
                      step={field.kind === 'number' ? field.step : undefined}
                      onChange={(event) => setValue(field.name, event.target.value)}
                    />
                  )
                }
              </FormField>
            );
          })}
        </div>
      </form>
    </Modal>
  );
}
