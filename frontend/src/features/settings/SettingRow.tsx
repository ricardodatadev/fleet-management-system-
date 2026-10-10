import { useId, useState } from 'react';
import { ApiError, GENERIC_ERROR_MESSAGE } from '@/api';
import { Badge, Button, ConfirmDialog, RadioGroup, Switch, useToast } from '@/components/ui';
import { useEffectiveSetting, useSettingMutations } from './api';
import { isDefinedHere, isOverrideHere, originLabel } from './origin';
import type { Scope, SettingDefinition, SettingValue } from './types';

const SCOPE_NAMES: Record<Scope['type'], string> = {
  global: 'global',
  branch: 'filial',
  family: 'família',
};

export const NO_CHANGE_PREFIX = 'Nenhuma alteração';

export interface SettingRowProps {
  definition: SettingDefinition;
  scope: Scope;
  /** Ex.: "Global", "Filial FIL-001 — Matriz". */
  scopeLabel: string;
  canManage: boolean;
}

function valueLabel(definition: SettingDefinition, value: SettingValue | null | undefined) {
  if (value === null || value === undefined) return '—';
  if (definition.type === 'bool') return value === true ? 'Sim' : 'Não';
  return definition.values?.find((option) => option.value === value)?.label ?? String(value);
}

function errorMessage(error: unknown) {
  return error instanceof ApiError ? error.message : GENERIC_ERROR_MESSAGE;
}

/**
 * Linha do Painel de Parâmetros (G.4-3): controle conforme o tipo (RadioGroup no enum, Switch no
 * bool), valor efetivo com a origem, Salvar (PUT, com aviso de auditoria) e Remover override
 * (DELETE; nunca no global). Escopo não permitido para a chave: linha indisponível.
 */
export function SettingRow({ definition, scope, scopeLabel, canManage }: SettingRowProps) {
  const labelId = useId();
  const { toast } = useToast();
  const allowed = definition.scopes.includes(scope.type);
  const effective = useEffectiveSetting(definition.key, scope);
  const { save, remove } = useSettingMutations(definition.key);
  const [confirm, setConfirm] = useState<'save' | 'remove' | null>(null);

  // Rascunho do valor: volta ao efetivo sempre que ele (ou o escopo) muda.
  const current = effective.data?.value as SettingValue | undefined;
  const sourceKey = `${scope.type}:${scope.id}:${String(current)}`;
  const [draft, setDraft] = useState<SettingValue | undefined>(current);
  const [prevSource, setPrevSource] = useState(sourceKey);
  if (prevSource !== sourceKey) {
    setPrevSource(sourceKey);
    setDraft(current);
  }

  const source = effective.data?.source;
  const overrideHere = source !== undefined && isOverrideHere(source, scope);
  const definedHere = source !== undefined && isDefinedHere(source, scope);
  const editable = canManage && allowed && effective.isSuccess;
  // Mesmo valor já definido aqui: nada a salvar. Herdado: salvar cria o override deste escopo.
  const dirty = draft !== undefined && (draft !== current || !definedHere);

  async function onSave() {
    if (draft === undefined) return;
    try {
      const message = await save.mutateAsync({ scope, value: draft });
      setConfirm(null);
      const unchanged = message.startsWith(NO_CHANGE_PREFIX);
      toast({
        tone: unchanged ? 'info' : 'success',
        title: unchanged ? 'Nada mudou' : 'Parâmetro salvo',
        description: message,
      });
    } catch {
      // A mensagem do envelope (422/403) aparece no próprio diálogo.
    }
  }

  async function onRemove() {
    try {
      const message = await remove.mutateAsync(scope);
      setConfirm(null);
      toast({ tone: 'success', title: 'Valor removido', description: message });
    } catch {
      // Idem: erro no diálogo.
    }
  }

  const controlDisabled = !editable;
  return (
    <section
      aria-labelledby={labelId}
      className="flex flex-col gap-4 border-t border-border py-6 first:border-t-0 lg:flex-row lg:items-start lg:justify-between"
    >
      <div className="flex max-w-xl flex-col gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <h3 id={labelId} className="font-bold text-text">
            {definition.label}
          </h3>
          <Badge tone="info">{definition.rule}</Badge>
          {definition.placeholder && (
            <Badge title="Será substituída pela matriz por categoria de serviço na fase de OS.">
              Provisória
            </Badge>
          )}
        </div>
        <p className="text-sm text-text-muted">{definition.description}</p>
        {!allowed && (
          <p className="text-sm text-text-muted">
            Indisponível por {SCOPE_NAMES[scope.type]}: este parâmetro só pode ser definido em{' '}
            {definition.scopes.map((s) => SCOPE_NAMES[s]).join(', ')}.
          </p>
        )}
      </div>

      <div className="flex min-w-72 flex-col gap-3">
        {effective.isError ? (
          <p role="alert" className="text-sm font-bold text-danger">
            {errorMessage(effective.error)}
          </p>
        ) : definition.type === 'enum' ? (
          <RadioGroup
            aria-labelledby={labelId}
            value={draft === undefined ? '' : String(draft)}
            onValueChange={(value) => setDraft(value)}
            disabled={controlDisabled}
            options={(definition.values ?? []).map((option) => ({
              value: option.value,
              label: option.label,
            }))}
          />
        ) : (
          <div className="flex items-center gap-2">
            <Switch
              aria-labelledby={labelId}
              checked={draft === true}
              onCheckedChange={(checked) => setDraft(checked)}
              disabled={controlDisabled}
            />
            <span aria-hidden="true" className="text-text">
              {draft === true ? 'Sim' : 'Não'}
            </span>
          </div>
        )}

        <div className="flex flex-wrap items-center gap-2" aria-live="polite">
          {effective.isPending ? (
            <span className="text-sm text-text-muted">Carregando…</span>
          ) : (
            source !== undefined && (
              <>
                <span className="text-sm text-text-muted">
                  Vale: <strong className="text-text">{valueLabel(definition, current)}</strong>
                </span>
                <Badge tone={definedHere ? 'success' : 'neutral'}>
                  {originLabel(source, scope)}
                </Badge>
              </>
            )
          )}
        </div>

        {canManage && allowed && (
          <div className="flex flex-wrap gap-2">
            <Button
              disabled={!dirty || !effective.isSuccess}
              onClick={() => {
                save.reset();
                setConfirm('save');
              }}
            >
              Salvar
            </Button>
            {overrideHere && (
              <Button
                variant="secondary"
                onClick={() => {
                  remove.reset();
                  setConfirm('remove');
                }}
              >
                Remover override
              </Button>
            )}
          </div>
        )}
      </div>

      <ConfirmDialog
        open={confirm === 'save'}
        onOpenChange={(open) => !open && !save.isPending && setConfirm(null)}
        title="Salvar parâmetro?"
        description={`${definition.label}: ${valueLabel(definition, draft)} em ${scopeLabel}. A alteração fica registrada em auditoria.`}
        confirmLabel="Salvar"
        loading={save.isPending}
        error={save.isError ? errorMessage(save.error) : null}
        onConfirm={() => void onSave()}
      />
      <ConfirmDialog
        open={confirm === 'remove'}
        onOpenChange={(open) => !open && !remove.isPending && setConfirm(null)}
        title="Remover o valor deste escopo?"
        description={`${definition.label} deixa de ter valor próprio em ${scopeLabel} e volta a herdar do nível acima. A remoção fica registrada em auditoria.`}
        confirmLabel="Remover"
        destructive
        loading={remove.isPending}
        error={remove.isError ? errorMessage(remove.error) : null}
        onConfirm={() => void onRemove()}
      />
    </section>
  );
}
