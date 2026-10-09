import { useState } from 'react';
import type { FormEvent } from 'react';
import { ApiError, GENERIC_ERROR_MESSAGE } from '@/api';
import { Button, FormField, Input, Modal, Select, Switch } from '@/components/ui';
import type { SelectOption } from '@/components/ui';
import { initialValues, splitFieldErrors, toPayload, validate } from './form';
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

function selectOptions<T extends CrudRow>(field: CrudField<T>, row: T | null, value: string) {
  if (field.kind !== 'select') return [];
  const current = row && field.currentOption?.(row);
  const missing = current && value !== '' && !field.options.some((o) => o.value === value);
  return missing ? [...field.options, current as SelectOption] : field.options;
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
    setValues((current) => ({ ...current, [name]: value }));
    setFieldErrors((current) => {
      const next = { ...current };
      delete next[name];
      return next;
    });
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setFormError(null);
    const clientErrors = splitFieldErrors(resource.fields, validate(resource.fields, values));
    if (Object.keys(clientErrors.byField).length > 0) {
      setFieldErrors(clientErrors.byField);
      return;
    }
    setSaving(true);
    try {
      await onSubmit(toPayload(resource.fields, values));
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
          <p role="alert" className="rounded-lg bg-red-50 p-3 font-medium text-red-900">
            {formError}
          </p>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          {resource.fields.map((field) => {
            const value = values[field.name];
            const error = fieldErrors[field.name];
            if (field.kind === 'switch') {
              return (
                <div key={field.name} className="flex flex-col justify-end gap-2">
                  <Switch
                    label={field.label}
                    checked={Boolean(value)}
                    onCheckedChange={(checked) => setValue(field.name, checked)}
                  />
                  {error && <p className="text-sm font-medium text-danger-600">{error}</p>}
                </div>
              );
            }
            const text = typeof value === 'string' ? value : '';
            return (
              <FormField
                key={field.name}
                label={field.label}
                required={field.required}
                help={field.help}
                error={error}
              >
                {(control) =>
                  field.kind === 'select' ? (
                    <Select
                      {...control}
                      invalid={Boolean(error)}
                      value={text}
                      disabled={field.loading}
                      placeholder={
                        field.loading ? 'Carregando…' : (field.placeholder ?? 'Selecione')
                      }
                      options={selectOptions(field, row, text)}
                      onChange={(event) => setValue(field.name, event.target.value)}
                    />
                  ) : (
                    <Input
                      {...control}
                      invalid={Boolean(error)}
                      value={text}
                      type={field.kind === 'number' ? 'number' : 'text'}
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
