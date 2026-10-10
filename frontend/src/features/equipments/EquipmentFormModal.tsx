import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import type { FieldPath } from 'react-hook-form';
import { ApiError, GENERIC_ERROR_MESSAGE, api } from '@/api';
import {
  Button,
  FormField,
  Input,
  Modal,
  Select,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
  Textarea,
  useToast,
} from '@/components/ui';
import type { SelectOption } from '@/components/ui';
import { CRITICALITY_LABELS, enumOptions } from '@/features/cadastros/labels';
import {
  useBranchOptions,
  useCostCenterLookup,
  useEmployeeOptions,
  useFamilyOptions,
  useMetaEnums,
} from '@/features/cadastros/lookups';
import { zodResolver } from '@/lib/zodResolver';
import { STATUS_LABELS } from './labels';
import { FIELD_TAB, FORM_FIELDS, equipmentDefaults, equipmentSchema } from './schema';
import type { EquipmentFormValues, EquipmentPayload, FormField as Field } from './schema';
import type { Equipment } from './types';

type Tab = 'gerais' | 'financeiro';

export interface EquipmentFormModalProps {
  /** `null` = cadastro. */
  row: Equipment | null;
  onClose: () => void;
}

const ref = (item: { id: number; code: string; name: string }): SelectOption => ({
  value: String(item.id),
  label: `${item.code} — ${item.name}`,
});

/** Inclui a opção atual quando ela não vem no lookup (ex.: registro inativo, outra página). */
function withCurrent(options: SelectOption[], current: SelectOption | null): SelectOption[] {
  return current && !options.some((o) => o.value === current.value)
    ? [...options, current]
    : options;
}

/**
 * Formulário de equipamento (G.4-2, F1-26): Modal com abas Gerais e Financeiro (Telemetria
 * desabilitada). react-hook-form + zod espelham a API; o 422 do servidor vai para o campo e leva à
 * aba do primeiro erro. Trocar a filial limpa centro de custo e responsável.
 */
export function EquipmentFormModal({ row, onClose }: EquipmentFormModalProps) {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [tab, setTab] = useState<Tab>('gerais');
  const [formError, setFormError] = useState<string | null>(null);

  const form = useForm<EquipmentFormValues, unknown, EquipmentPayload>({
    defaultValues: equipmentDefaults(row),
    resolver: zodResolver(equipmentSchema()),
    mode: 'onSubmit',
    reValidateMode: 'onChange',
  });
  const { control, register, handleSubmit, formState, setError, setFocus, setValue } = form;
  const errors = formState.errors;
  const branchId = useWatch({ control, name: 'branch_id' });

  const enums = useMetaEnums();
  const families = useFamilyOptions();
  const branches = useBranchOptions();
  const costCenters = useCostCenterLookup();
  const employees = useEmployeeOptions(branchId);

  const costCenterOptions = withCurrent(
    (costCenters.data ?? [])
      .filter((cc) => !cc.branch || String(cc.branch.id) === branchId)
      .map(ref),
    row?.cost_center && String(row.branch.id) === branchId ? ref(row.cost_center) : null,
  );
  const responsibleOptions = withCurrent(
    employees.data ?? [],
    row?.responsible_employee && String(row.branch.id) === branchId
      ? {
          value: String(row.responsible_employee.id),
          label: `${row.responsible_employee.name} (${row.responsible_employee.registration})`,
        }
      : null,
  );

  /** Leva o usuário à aba do primeiro campo com erro e foca o campo. */
  function revealFirstError(fields: Field[]) {
    const first = FORM_FIELDS.find((field) => fields.includes(field));
    if (!first) return;
    setTab(FIELD_TAB[first]);
    // Espera a aba aparecer antes de focar.
    setTimeout(() => setFocus(first), 0);
  }

  const save = useMutation({
    mutationFn: (payload: EquipmentPayload) =>
      row
        ? api.put<Equipment>(`/equipments/${row.id}`, payload)
        : api.post<Equipment>('/equipments', payload),
    onSuccess: (saved) => {
      toast({
        tone: 'success',
        title: row ? 'Alterações salvas' : 'Equipamento cadastrado',
        description: `${saved.code} — ${saved.name}`,
      });
      void queryClient.invalidateQueries({ queryKey: ['/equipments'] });
      onClose();
    },
    onError: (error) => {
      if (error instanceof ApiError && error.isValidation && error.errors) {
        const known: Field[] = [];
        const other: string[] = [];
        for (const [field, messages] of Object.entries(error.errors)) {
          const message = messages[0];
          if (!message) continue;
          if ((FORM_FIELDS as readonly string[]).includes(field)) {
            setError(field as FieldPath<EquipmentFormValues>, { type: 'server', message });
            known.push(field as Field);
          } else {
            other.push(message);
          }
        }
        setFormError(other.length > 0 ? other.join(' ') : error.message);
        revealFirstError(known);
        return;
      }
      setFormError(error instanceof ApiError ? error.message : GENERIC_ERROR_MESSAGE);
    },
  });

  const onValid = (payload: EquipmentPayload) => {
    setFormError(null);
    save.mutate(payload);
  };
  const onInvalid = (invalid: Partial<Record<Field, unknown>>) =>
    revealFirstError(Object.keys(invalid) as Field[]);

  /** Props comuns de um campo: registro no RHF, erro e estado inválido. */
  function field(name: Field) {
    return { ...register(name), invalid: Boolean(errors[name]) };
  }
  const err = (name: Field) => errors[name]?.message;

  const branchField = register('branch_id', {
    onChange: () => {
      // Centro de custo e responsável dependem da filial.
      setValue('cost_center_id', '');
      setValue('responsible_employee_id', '');
    },
  });

  return (
    <Modal
      open
      onOpenChange={(open) => !open && !save.isPending && onClose()}
      title={row ? 'Editar frota' : 'Cadastrar Nova Frota'}
      description={row ? `${row.code} — ${row.name}` : undefined}
      className="max-w-3xl"
      footer={
        <>
          <Button variant="secondary" disabled={save.isPending} onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" form="equipment-form" loading={save.isPending}>
            Salvar
          </Button>
        </>
      }
    >
      <form
        id="equipment-form"
        noValidate
        onSubmit={handleSubmit(onValid, onInvalid)}
        className="flex flex-col gap-4"
      >
        {formError && (
          <p role="alert" className="rounded-control bg-danger-subtle p-3 font-bold text-danger">
            {formError}
          </p>
        )}
        <Tabs
          value={tab}
          onValueChange={(value) => setTab(value as Tab)}
          className="flex flex-col gap-4"
        >
          <TabsList aria-label="Seções do cadastro">
            <TabsTrigger value="gerais">Gerais</TabsTrigger>
            <TabsTrigger value="financeiro">Financeiro</TabsTrigger>
            <TabsTrigger value="telemetria" disabled>
              Telemetria
            </TabsTrigger>
          </TabsList>

          {/* Abas montadas o tempo todo: o foco vai ao campo com erro mesmo em outra aba. */}
          <TabsContent value="gerais" forceMount className="data-[state=inactive]:hidden">
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField label="Código" required error={err('code')} help="Gravado em maiúsculas.">
                {(control) => <Input {...control} {...field('code')} maxLength={30} />}
              </FormField>
              <FormField label="Nome" required error={err('name')}>
                {(control) => <Input {...control} {...field('name')} maxLength={150} />}
              </FormField>
              <FormField label="Família/Classe" required error={err('family_id')}>
                {(control) => (
                  <Select
                    {...control}
                    {...field('family_id')}
                    disabled={families.isPending}
                    placeholder={families.isPending ? 'Carregando…' : 'Selecione'}
                    options={withCurrent(families.data ?? [], row ? ref(row.family) : null)}
                  />
                )}
              </FormField>
              <FormField label="Filial" required error={err('branch_id')}>
                {(control) => (
                  <Select
                    {...control}
                    {...branchField}
                    invalid={Boolean(errors.branch_id)}
                    disabled={branches.isPending}
                    placeholder={branches.isPending ? 'Carregando…' : 'Selecione'}
                    options={withCurrent(branches.data ?? [], row ? ref(row.branch) : null)}
                  />
                )}
              </FormField>
              <FormField
                label="Placa"
                error={err('plate')}
                help="Gravada em maiúsculas, sem hífen nem espaço."
              >
                {(control) => (
                  <Input
                    {...control}
                    {...field('plate')}
                    autoCapitalize="characters"
                    maxLength={10}
                  />
                )}
              </FormField>
              <FormField label="Nº de série" error={err('serial_number')}>
                {(control) => <Input {...control} {...field('serial_number')} maxLength={60} />}
              </FormField>
              <FormField label="Fabricante" error={err('manufacturer')}>
                {(control) => <Input {...control} {...field('manufacturer')} maxLength={80} />}
              </FormField>
              <FormField label="Modelo" error={err('model')}>
                {(control) => <Input {...control} {...field('model')} maxLength={80} />}
              </FormField>
              <FormField label="Ano" error={err('year')}>
                {(control) => (
                  <Input
                    {...control}
                    {...field('year')}
                    type="number"
                    inputMode="numeric"
                    step={1}
                  />
                )}
              </FormField>
              <FormField
                label="Responsável"
                error={err('responsible_employee_id')}
                help={
                  branchId ? 'Colaboradores da filial escolhida.' : 'Escolha a filial primeiro.'
                }
              >
                {(control) => (
                  <Select
                    {...control}
                    {...field('responsible_employee_id')}
                    disabled={!branchId || employees.isFetching}
                    placeholder={employees.isFetching ? 'Carregando…' : 'Sem responsável'}
                    options={responsibleOptions}
                  />
                )}
              </FormField>
              <FormField label="Status" required error={err('status')}>
                {(control) => (
                  <Select
                    {...control}
                    {...field('status')}
                    options={enumOptions(enums.data?.equipment_statuses, STATUS_LABELS)}
                  />
                )}
              </FormField>
              <FormField
                label="Criticidade"
                error={err('criticality_override')}
                help="Em branco, vale a criticidade da família."
              >
                {(control) => (
                  <Select
                    {...control}
                    {...field('criticality_override')}
                    placeholder="Usar a da família"
                    options={enumOptions(enums.data?.criticalities, CRITICALITY_LABELS)}
                  />
                )}
              </FormField>
              <FormField label="Observações" error={err('notes')} className="sm:col-span-2">
                {(control) => <Textarea {...control} {...field('notes')} maxLength={5000} />}
              </FormField>
            </div>
          </TabsContent>

          <TabsContent value="financeiro" forceMount className="data-[state=inactive]:hidden">
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField
                label="Centro de custo"
                required
                error={err('cost_center_id')}
                help={
                  branchId ? 'Da filial escolhida ou sem filial.' : 'Escolha a filial primeiro.'
                }
              >
                {(control) => (
                  <Select
                    {...control}
                    {...field('cost_center_id')}
                    disabled={!branchId || costCenters.isPending}
                    placeholder={costCenters.isPending ? 'Carregando…' : 'Selecione'}
                    options={costCenterOptions}
                  />
                )}
              </FormField>
              <FormField label="Data de aquisição" error={err('acquisition_date')}>
                {(control) => <Input {...control} {...field('acquisition_date')} type="date" />}
              </FormField>
              <FormField label="Valor de aquisição (R$)" error={err('acquisition_value')}>
                {(control) => (
                  <Input
                    {...control}
                    {...field('acquisition_value')}
                    type="number"
                    inputMode="decimal"
                    min={0}
                    step={0.01}
                  />
                )}
              </FormField>
              <FormField label="Odômetro inicial (km)" error={err('odometer_km')}>
                {(control) => (
                  <Input
                    {...control}
                    {...field('odometer_km')}
                    type="number"
                    inputMode="decimal"
                    min={0}
                    step={0.1}
                  />
                )}
              </FormField>
              <FormField label="Horímetro inicial (h)" error={err('hour_meter')}>
                {(control) => (
                  <Input
                    {...control}
                    {...field('hour_meter')}
                    type="number"
                    inputMode="decimal"
                    min={0}
                    step={0.1}
                  />
                )}
              </FormField>
            </div>
          </TabsContent>
        </Tabs>
      </form>
    </Modal>
  );
}
