import { screen, within } from '@testing-library/react';
import { axe } from 'jest-axe';
import { mockAuthApi, renderApp, storeSession } from '@/test/auth';
import { branch, mockCrudApi, mockMetaEnums } from '@/test/crud';
import { SEED, mockSettingsApi } from '@/test/settings';

/** Gate da F1-29: 0 violações axe nas telas principais (Login, Equipamentos, Parâmetros). */

function equipment(id: number, code: string) {
  return {
    id,
    code,
    name: `Equipamento ${id}`,
    family: { id: 1, code: 'CAM', name: 'Caminhões' },
    branch: { id: 1, code: 'FIL-001', name: 'Matriz' },
    cost_center: { id: 9, code: 'CC-0900', name: 'Corporativo' },
    responsible_employee: null,
    plate: null,
    serial_number: null,
    manufacturer: null,
    model: null,
    year: null,
    status: 'active',
    criticality: 'medium',
    criticality_source: 'family',
    criticality_override: null,
    odometer_km: 0,
    hour_meter: 0,
    acquisition_date: null,
    acquisition_value: null,
    notes: null,
    created_at: null,
    updated_at: null,
    deleted_at: null,
  };
}

function mockApis() {
  mockMetaEnums();
  mockCrudApi('/branches', [branch(1, 'FIL-001', 'Matriz'), branch(2, 'FIL-002', 'Norte')], {
    filters: ['is_active', 'type'],
    sortable: ['code', 'name', 'type', 'is_active', 'created_at', 'updated_at'],
    build: (b, id) => ({ ...branch(id, '', ''), ...b }),
  });
  mockCrudApi('/equipments', [equipment(1, 'CAM-001'), equipment(2, 'CAM-002')], {
    filters: ['status'],
    sortable: ['code', 'name', 'plate', 'status', 'year', 'acquisition_date'],
    build: (b, id) => ({ ...equipment(id, ''), ...b }) as never,
  });
  mockSettingsApi(SEED);
}

async function open(path: string, heading: string) {
  mockAuthApi('admin');
  storeSession();
  renderApp(path);
  await screen.findByRole('heading', { level: 1, name: heading, hidden: true });
}

describe('axe nas telas principais (F1-29)', () => {
  it('Login: 0 violações', async () => {
    mockAuthApi('admin');
    const { container } = renderApp('/login');
    await screen.findByRole('heading', { name: 'Entrar' });
    expect(await axe(container)).toHaveNoViolations();
  });

  it('Equipamentos (shell + lista): 0 violações; Drawer 360° aberto: 0 violações', async () => {
    mockApis();
    await open('/ativos/equipamentos', 'Frotas & Equipamentos');
    await screen.findByText('CAM-001');
    expect(await axe(document.body)).toHaveNoViolations();
    // O drawer vai num portal: axe no próprio diálogo (a página fica aria-hidden por baixo).
    mockAuthApi('admin');
    renderApp('/ativos/equipamentos?id=1');
    const dialog = await screen.findByRole('dialog', { name: /CAM-001/ });
    await within(dialog).findByRole('region', { name: 'Identificação' });
    expect(await axe(dialog)).toHaveNoViolations();
  });

  it('Parâmetros (escopo Filial com as 4 chaves): 0 violações', async () => {
    mockApis();
    await open('/parametros?escopo=filial&id=1', 'Painel de Parâmetros');
    await screen.findAllByText(/Definido nesta filial|Herdado de Global/);
    expect(await axe(document.body)).toHaveNoViolations();
  });
});
