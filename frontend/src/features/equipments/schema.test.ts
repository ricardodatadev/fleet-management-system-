import {
  NOT_NEGATIVE,
  PLATE_MESSAGE,
  REQUIRED,
  equipmentDefaults,
  equipmentSchema,
  normalizePlate,
} from './schema';
import type { EquipmentFormValues } from './schema';

const NOW = new Date('2026-10-10T12:00:00Z');
const schema = equipmentSchema(NOW);

const valid: EquipmentFormValues = {
  ...equipmentDefaults(null),
  code: ' cam-010 ',
  name: 'Caminhão novo',
  family_id: '1',
  branch_id: '2',
  cost_center_id: '9',
};

const errorsOf = (values: Partial<EquipmentFormValues>) => {
  const result = schema.safeParse({ ...valid, ...values });
  return result.success
    ? {}
    : Object.fromEntries(result.error.issues.map((i) => [i.path.join('.'), i.message]));
};

describe('equipmentSchema (espelha a API)', () => {
  it('normalizePlate: maiúsculas, sem espaço nem hífen', () => {
    expect(normalizePlate('abc-1d23')).toBe('ABC1D23');
    expect(normalizePlate(' ab c 1234 ')).toBe('ABC1234');
  });

  it('payload: ids numéricos, vazios opcionais → null, medidores padrão 0, status padrão ativo', () => {
    const result = schema.parse({
      ...valid,
      plate: 'abc-1d23',
      year: '2027',
      acquisition_value: '450000.5',
    });
    expect(result).toEqual({
      code: 'cam-010',
      name: 'Caminhão novo',
      family_id: 1,
      branch_id: 2,
      cost_center_id: 9,
      responsible_employee_id: null,
      plate: 'ABC1D23',
      serial_number: null,
      manufacturer: null,
      model: null,
      year: 2027,
      status: 'active',
      criticality_override: null,
      odometer_km: 0,
      hour_meter: 0,
      acquisition_date: null,
      acquisition_value: 450000.5,
      notes: null,
    });
  });

  it('obrigatórios: código, nome, família, filial e centro de custo', () => {
    expect(
      errorsOf({ code: '  ', name: '', family_id: '', branch_id: '', cost_center_id: '' }),
    ).toEqual({
      code: REQUIRED,
      name: REQUIRED,
      family_id: REQUIRED,
      branch_id: REQUIRED,
      cost_center_id: REQUIRED,
    });
  });

  it.each(['AB1', 'ABCD12345', 'ÁBC1234', 'ABC_123'])('placa inválida: %s', (plate) => {
    expect(errorsOf({ plate })).toEqual({ plate: PLATE_MESSAGE });
  });

  it('ano entre 1950 e o ano atual + 1', () => {
    expect(errorsOf({ year: '1949' })).toEqual({ year: 'Informe um ano entre 1950 e 2027.' });
    expect(errorsOf({ year: '2028' })).toEqual({ year: 'Informe um ano entre 1950 e 2027.' });
    expect(errorsOf({ year: '2020.5' })).toHaveProperty('year');
    expect(errorsOf({ year: '1950' })).toEqual({});
  });

  it('números ≥ 0 e casas decimais (medidores 1, valor 2)', () => {
    expect(errorsOf({ odometer_km: '-1' })).toEqual({ odometer_km: NOT_NEGATIVE });
    expect(errorsOf({ hour_meter: '10.25' })).toHaveProperty('hour_meter');
    expect(errorsOf({ acquisition_value: '10.123' })).toHaveProperty('acquisition_value');
    expect(errorsOf({ acquisition_value: '10,50' })).toEqual({});
  });

  it('criticidade em branco = herdar da família; tamanho máximo das observações', () => {
    expect(schema.parse({ ...valid, criticality_override: 'high' }).criticality_override).toBe(
      'high',
    );
    expect(errorsOf({ notes: 'x'.repeat(5001) })).toHaveProperty('notes');
  });
});
