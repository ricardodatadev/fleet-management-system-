import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { mockAuthApi, renderApp, storeSession } from '@/test/auth';
import { branch, mockCrudApi, mockMetaEnums } from '@/test/crud';
import { SEED, mockSettingsApi } from '@/test/settings';
import { findSpacingViolations } from '@/test/spacing';
import { setViewport } from '@/test/viewport';

/**
 * Gate da F1-29 (G.1): espaçamento >= 8px entre alvos no shell, toolbars, formulários e ações de
 * tabela, nas telas reais.
 */

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

describe('espaçamento >= 8px entre alvos (G.1, F1-29)', () => {
  it('regra: detecta alvos colados e aceita gap-2 ou mais', () => {
    render(
      <div>
        <div data-testid="colado" className="flex">
          <button type="button">A</button>
          <button type="button">B</button>
        </div>
        <div className="flex gap-2">
          <button type="button">C</button>
          <button type="button">D</button>
        </div>
        <div className="flex gap-1">
          <button type="button">E</button>
          <button type="button">F</button>
        </div>
      </div>,
    );
    const violations = findSpacingViolations();
    expect(violations).toHaveLength(2);
    expect(violations.map((v) => v.spacing)).toEqual([0, 1]);
  });

  it('shell desktop + lista de equipamentos (toolbar, cabeçalhos, ações de linha, paginação)', async () => {
    mockApis();
    await open('/ativos/equipamentos', 'Frotas & Equipamentos');
    await screen.findByText('CAM-001');
    expect(findSpacingViolations()).toEqual([]);
  });

  it('shell mobile (375): topbar com menu, avatar e drawer de navegação aberto', async () => {
    setViewport(375);
    mockApis();
    await open('/ativos/equipamentos', 'Frotas & Equipamentos');
    await screen.findByText('CAM-001');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Abrir menu' }));
    await screen.findByRole('dialog', { name: 'Menu' });
    expect(findSpacingViolations()).toEqual([]);
  });

  it('formulário de equipamento (abas Gerais e Financeiro montadas) e menu do usuário', async () => {
    mockApis();
    await open('/ativos/equipamentos', 'Frotas & Equipamentos');
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: 'Cadastrar Nova Frota' }));
    const dialog = await screen.findByRole('dialog', { name: 'Cadastrar Nova Frota' });
    await within(dialog).findByRole('textbox', { name: /Código/ });
    expect(findSpacingViolations()).toEqual([]);
  });

  it('cadastro genérico (Unidades) com o modal aberto', async () => {
    mockApis();
    await open('/cadastros/unidades', 'Unidades/Filiais');
    await screen.findByText('Matriz');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Nova unidade' }));
    await screen.findByRole('dialog', { name: 'Nova unidade' });
    expect(findSpacingViolations()).toEqual([]);
  });

  it('Painel de Parâmetros (seletor de escopo, RadioGroup, Switch, ações)', async () => {
    mockApis();
    await open('/parametros?escopo=filial&id=1', 'Painel de Parâmetros');
    await screen.findAllByText(/Definido nesta filial|Herdado de Global/);
    expect(findSpacingViolations()).toEqual([]);
  });

  it('Login', async () => {
    mockAuthApi('admin');
    renderApp('/login');
    await screen.findByRole('heading', { name: 'Entrar' });
    expect(findSpacingViolations()).toEqual([]);
  });
});
