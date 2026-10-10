/**
 * Teste consolidado do alvo mínimo de toque (spec G.1 / F1-22): todo controle interativo
 * dos componentes base usa as classes de TAP_MIN (48px).
 */
import { render, screen } from '@testing-library/react';
import { Pencil } from 'lucide-react';
import { MemoryRouter } from 'react-router-dom';
import { TAP_MIN, TAP_MIN_CLASSES } from '@/lib/tokens';
import {
  Button,
  Checkbox,
  DataTable,
  IconButton,
  Input,
  NavItem,
  RadioGroup,
  Select,
  Switch,
  Tabs,
  TabsList,
  TabsTrigger,
} from '.';

describe(`alvo mínimo de toque (${TAP_MIN}px)`, () => {
  it('todos os controles interativos têm min-h-12 min-w-12', () => {
    render(
      <MemoryRouter>
        <Button>Botão</Button>
        <IconButton label="Ícone" icon={<Pencil />} />
        <Input aria-label="Entrada" />
        <Select aria-label="Seleção" options={[{ value: 'a', label: 'A' }]} />
        <Switch aria-label="Chave" />
        <Checkbox aria-label="Caixa" />
        <RadioGroup aria-label="Rádio" options={[{ value: 'a', label: 'Opção A' }]} />
        <Tabs defaultValue="a">
          <TabsList aria-label="Abas">
            <TabsTrigger value="a">Aba</TabsTrigger>
          </TabsList>
        </Tabs>
        <ul>
          <NavItem label="Item" to="/x" />
        </ul>
        <DataTable
          caption="Tabela"
          columns={[
            { key: 'c', header: 'Col', sortable: true, cell: () => 'v' },
            {
              key: 'a',
              header: 'Ações',
              cell: () => <IconButton label="Ação de linha" icon={<Pencil />} />,
            },
          ]}
          rows={[{ id: 1 }]}
          rowKey={(r) => r.id}
        />
      </MemoryRouter>,
    );

    const controls = [
      ...screen.getAllByRole('button'),
      screen.getByRole('textbox'),
      screen.getByRole('combobox'),
      screen.getByRole('switch'),
      screen.getByRole('checkbox'),
      screen.getByRole('radio'),
      screen.getByRole('tab'),
      screen.getByRole('link'),
    ];
    const classes = TAP_MIN_CLASSES.split(' ');
    expect(controls.length).toBeGreaterThanOrEqual(10);
    controls.forEach((control) => expect(control).toHaveClass(...classes));
  });
});
