import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { Pencil } from 'lucide-react';
import type { ComponentProps } from 'react';
import { DataTable } from './DataTable';
import type { DataTableColumn } from './DataTable';
import { IconButton } from './IconButton';

interface Row {
  id: number;
  code: string;
  name: string;
}

const rows: Row[] = [
  { id: 1, code: 'CM-01', name: 'Caminhão 1' },
  { id: 2, code: 'EX-02', name: 'Escavadeira 2' },
];

const columns: DataTableColumn<Row>[] = [
  { key: 'code', header: 'Código', sortable: true, cell: (r) => r.code },
  { key: 'name', header: 'Nome', sortable: true, cell: (r) => r.name },
  {
    key: 'actions',
    header: 'Ações',
    cell: (r) => <IconButton label={`Editar ${r.code}`} icon={<Pencil />} />,
  },
];

const meta = { current_page: 2, per_page: 2, total: 6, last_page: 3 };

type Props = ComponentProps<typeof DataTable<Row>>;

function renderTable(props: Partial<Props> = {}) {
  return render(
    <DataTable<Row>
      caption="Equipamentos"
      columns={columns}
      rows={rows}
      rowKey={(r) => r.id}
      {...props}
    />,
  );
}

describe('DataTable', () => {
  it('renderiza cabeçalhos e linhas com legenda acessível', () => {
    renderTable();
    expect(screen.getByRole('table', { name: 'Equipamentos' })).toBeInTheDocument();
    expect(screen.getAllByRole('row')).toHaveLength(3);
    expect(screen.getByRole('cell', { name: 'Escavadeira 2' })).toBeInTheDocument();
  });

  it('ordenação: cicla asc → desc → nenhuma e reflete aria-sort', async () => {
    const user = userEvent.setup();
    const onSortChange = vi.fn();
    const { rerender } = renderTable({ onSortChange, sort: null });
    const header = screen.getByRole('columnheader', { name: /Código/ });
    expect(header).toHaveAttribute('aria-sort', 'none');
    expect(screen.getByRole('columnheader', { name: 'Ações' })).not.toHaveAttribute('aria-sort');

    await user.click(screen.getByRole('button', { name: /Código/ }));
    expect(onSortChange).toHaveBeenLastCalledWith({ field: 'code', direction: 'asc' });

    rerender(
      <DataTable<Row>
        caption="Equipamentos"
        columns={columns}
        rows={rows}
        rowKey={(r) => r.id}
        sort={{ field: 'code', direction: 'asc' }}
        onSortChange={onSortChange}
      />,
    );
    expect(header).toHaveAttribute('aria-sort', 'ascending');
    await user.click(screen.getByRole('button', { name: /Código/ }));
    expect(onSortChange).toHaveBeenLastCalledWith({ field: 'code', direction: 'desc' });

    rerender(
      <DataTable<Row>
        caption="Equipamentos"
        columns={columns}
        rows={rows}
        rowKey={(r) => r.id}
        sort={{ field: 'code', direction: 'desc' }}
        onSortChange={onSortChange}
      />,
    );
    expect(header).toHaveAttribute('aria-sort', 'descending');
    await user.click(screen.getByRole('button', { name: /Código/ }));
    expect(onSortChange).toHaveBeenLastCalledWith(null);
  });

  it('paginação server-side: mostra meta e navega', async () => {
    const user = userEvent.setup();
    const onPageChange = vi.fn();
    renderTable({ meta, onPageChange });
    const nav = screen.getByRole('navigation', { name: 'Paginação' });
    expect(nav).toHaveTextContent('Página 2 de 3 · 6 registros');
    await user.click(within(nav).getByRole('button', { name: /Anterior/ }));
    expect(onPageChange).toHaveBeenLastCalledWith(1);
    await user.click(within(nav).getByRole('button', { name: /Próxima/ }));
    expect(onPageChange).toHaveBeenLastCalledWith(3);
  });

  it('desabilita Anterior na primeira página e Próxima na última', () => {
    const { unmount } = renderTable({ meta: { ...meta, current_page: 1 } });
    expect(screen.getByRole('button', { name: /Anterior/ })).toBeDisabled();
    unmount();
    renderTable({ meta: { ...meta, current_page: 3 } });
    expect(screen.getByRole('button', { name: /Próxima/ })).toBeDisabled();
  });

  it('página vazia com total > 0 mantém a paginação (ex.: excluiu o último item da página)', async () => {
    const user = userEvent.setup();
    const onPageChange = vi.fn();
    renderTable({ rows: [], meta: { ...meta, current_page: 3, total: 4 }, onPageChange });
    expect(screen.getByRole('status')).toHaveTextContent('Nenhum registro encontrado');
    const nav = screen.getByRole('navigation', { name: 'Paginação' });
    expect(within(nav).getByRole('button', { name: /Próxima/ })).toBeDisabled();
    await user.click(within(nav).getByRole('button', { name: /Anterior/ }));
    expect(onPageChange).toHaveBeenLastCalledWith(2);
  });

  it('sem registros (total 0) ou com erro não mostra paginação', () => {
    const { unmount } = renderTable({ rows: [], meta: { ...meta, current_page: 1, total: 0 } });
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    unmount();
    renderTable({ error: 'Falha', meta });
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });

  it('loading: skeleton, aria-busy e sem paginação', () => {
    renderTable({ loading: true, meta, skeletonRows: 3 });
    expect(screen.getByRole('table')).toHaveAttribute('aria-busy', 'true');
    expect(screen.getAllByTestId('skeleton-row')).toHaveLength(3);
    expect(screen.queryByText('Caminhão 1')).not.toBeInTheDocument();
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });

  it('vazio: mostra EmptyState', () => {
    renderTable({ rows: [], emptyTitle: 'Nenhuma frota', emptyDescription: 'Ajuste os filtros.' });
    expect(screen.getByRole('status')).toHaveTextContent('Nenhuma frota');
    expect(screen.getByText('Ajuste os filtros.')).toBeInTheDocument();
  });

  it('erro: mostra alerta com "Tentar novamente"', async () => {
    const user = userEvent.setup();
    const onRetry = vi.fn();
    renderTable({ error: 'Erro interno.', onRetry });
    expect(screen.getByRole('alert')).toHaveTextContent('Erro interno.');
    expect(screen.queryByText('Caminhão 1')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Tentar novamente' }));
    expect(onRetry).toHaveBeenCalledTimes(1);
  });

  it('onRowClick: clique na linha e botão da 1ª coluna acessível por teclado', async () => {
    const user = userEvent.setup();
    const onRowClick = vi.fn();
    renderTable({ onRowClick });
    await user.click(screen.getByRole('cell', { name: 'Escavadeira 2' }));
    expect(onRowClick).toHaveBeenLastCalledWith(rows[1]);
    const rowButton = screen.getByRole('button', { name: 'CM-01' });
    expect(rowButton).toHaveClass('min-h-12', 'min-w-12');
    rowButton.focus();
    await user.keyboard('{Enter}');
    expect(onRowClick).toHaveBeenLastCalledWith(rows[0]);
    expect(onRowClick).toHaveBeenCalledTimes(2);
  });

  it('clicar num controle dentro da célula não aciona onRowClick', async () => {
    const user = userEvent.setup();
    const onRowClick = vi.fn();
    const onEdit = vi.fn();
    const withAction: DataTableColumn<Row>[] = [
      ...columns.slice(0, 2),
      {
        key: 'actions',
        header: 'Ações',
        cell: (r) => (
          <IconButton label={`Excluir ${r.code}`} icon={<Pencil />} onClick={() => onEdit(r)} />
        ),
      },
    ];
    renderTable({ columns: withAction, onRowClick });
    await user.click(screen.getByRole('button', { name: 'Excluir EX-02' }));
    expect(onEdit).toHaveBeenCalledWith(rows[1]);
    expect(onRowClick).not.toHaveBeenCalled();
  });

  it('botões de ordenação, paginação e ação de linha têm área mínima de 48px', () => {
    renderTable({ meta });
    [
      screen.getByRole('button', { name: /Código/ }),
      screen.getByRole('button', { name: /Anterior/ }),
      screen.getByRole('button', { name: 'Editar CM-01' }),
    ].forEach((b) => expect(b).toHaveClass('min-h-12', 'min-w-12'));
  });

  it.each<[string, Partial<Props>]>([
    ['com dados', {}],
    ['carregando', { loading: true }],
    ['vazio', { rows: [] }],
    ['erro', { error: 'Falha' }],
  ])('não tem violações de acessibilidade (%s)', async (_label, props) => {
    const { container } = renderTable({ meta, onRowClick: () => {}, ...props });
    expect(await axe(container)).toHaveNoViolations();
  });
});
