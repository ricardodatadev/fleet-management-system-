import {
  REQUIRED_MESSAGE,
  initialValues,
  isRequired,
  splitFieldErrors,
  toPayload,
  validate,
} from './form';
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

  describe('campos por contexto (F1-31)', () => {
    const DYNAMIC: CrudField<Row>[] = [
      { kind: 'select', name: 'job_type', label: 'Tipo', options: [], required: true },
      {
        kind: 'text',
        name: 'cnh_number',
        label: 'CNH',
        nullable: true,
        required: true,
        visible: (values) => values.job_type === 'driver',
      },
      {
        kind: 'password',
        name: 'password',
        label: 'Senha',
        required: ({ row }) => row === null,
      },
      {
        kind: 'select',
        name: 'role',
        label: 'Perfil',
        options: [],
        locked: (row) => (row?.id === 7 ? 'Travado.' : null),
      },
    ];

    it('campo oculto e nullable vai null (limpa o tipo anterior) e não é validado', () => {
      const values = { job_type: 'mechanic', cnh_number: '123', password: 'x', role: 'admin' };
      expect(toPayload(DYNAMIC, values)).toEqual({
        job_type: 'mechanic',
        cnh_number: null,
        password: 'x',
        role: 'admin',
      });
      expect(validate(DYNAMIC, { ...values, cnh_number: '' })).toEqual({});
      expect(validate(DYNAMIC, { ...values, job_type: 'driver', cnh_number: '' })).toEqual({
        cnh_number: [REQUIRED_MESSAGE],
      });
    });

    it('obrigatório por contexto: senha só no create; travado sai do payload e da validação', () => {
      expect(isRequired(DYNAMIC[2] as CrudField<Row>, { row: null, values: {} })).toBe(true);
      expect(isRequired(DYNAMIC[2] as CrudField<Row>, { row: ROW, values: {} })).toBe(false);
      expect(validate(DYNAMIC, { job_type: 'x', password: '' }, ROW)).toEqual({});
      expect(validate(DYNAMIC, { job_type: 'x', password: '' })).toEqual({
        password: [REQUIRED_MESSAGE],
      });
      // role travado não vai; cnh_number oculto (e nullable) vai null; senha em branco não vai.
      expect(toPayload(DYNAMIC, { job_type: 'x', password: '', role: 'admin' }, ROW)).toEqual({
        job_type: 'x',
        cnh_number: null,
      });
    });

    it('senha não leva trim; em branco na edição não vai', () => {
      expect(toPayload(DYNAMIC, { job_type: 'x', password: ' Senha 123 ' }).password).toBe(
        ' Senha 123 ',
      );
      expect(toPayload(DYNAMIC, { job_type: 'x', password: '' }, ROW)).not.toHaveProperty(
        'password',
      );
    });
  });
});
