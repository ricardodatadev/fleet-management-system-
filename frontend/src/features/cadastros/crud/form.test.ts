import { REQUIRED_MESSAGE, initialValues, splitFieldErrors, toPayload, validate } from './form';
import type { CrudField, CrudRow } from './types';

interface Row extends CrudRow {
  code: string;
  city: string | null;
  branch: { id: number } | null;
  pct: number;
  is_active: boolean;
}

const FIELDS: CrudField<Row>[] = [
  { kind: 'text', name: 'code', label: 'Código', required: true },
  { kind: 'text', name: 'city', label: 'Cidade', nullable: true },
  {
    kind: 'select',
    name: 'branch_id',
    label: 'Filial',
    options: [],
    numeric: true,
    nullable: true,
    get: (row) => row.branch?.id ?? null,
  },
  { kind: 'number', name: 'pct', label: '%', defaultValue: '90' },
  { kind: 'switch', name: 'is_active', label: 'Ativo' },
];

const ROW: Row = {
  id: 7,
  code: 'FIL-007',
  city: null,
  branch: { id: 3 },
  pct: 92.5,
  is_active: false,
  deleted_at: null,
};

describe('crud/form', () => {
  it('initialValues: defaults na criação e valores (via get) na edição', () => {
    expect(initialValues(FIELDS)).toEqual({
      code: '',
      city: '',
      branch_id: '',
      pct: '90',
      is_active: true,
    });
    expect(initialValues(FIELDS, ROW)).toEqual({
      code: 'FIL-007',
      city: '',
      branch_id: '3',
      pct: '92.5',
      is_active: false,
    });
  });

  it('toPayload: números convertidos, vazio nullable → null, vazio não-nullable omitido', () => {
    expect(
      toPayload(FIELDS, { code: ' X ', city: '', branch_id: '3', pct: '', is_active: true }),
    ).toEqual({ code: 'X', city: null, branch_id: 3, is_active: true });
    expect(toPayload(FIELDS, initialValues(FIELDS, ROW))).toEqual({
      code: 'FIL-007',
      city: null,
      branch_id: 3,
      pct: 92.5,
      is_active: false,
    });
  });

  it('validate: só obrigatórios vazios (espaços contam como vazio)', () => {
    expect(validate(FIELDS, { code: '  ', is_active: false })).toEqual({
      code: [REQUIRED_MESSAGE],
    });
    expect(validate(FIELDS, { code: 'A' })).toEqual({});
  });

  it('splitFieldErrors: campos do formulário vs. demais mensagens', () => {
    expect(
      splitFieldErrors(FIELDS, { code: ['Duplicado.', 'Outro'], dependents: ['equipments'] }),
    ).toEqual({ byField: { code: 'Duplicado.' }, other: ['equipments'] });
    expect(splitFieldErrors(FIELDS, null)).toEqual({ byField: {}, other: [] });
  });
});
