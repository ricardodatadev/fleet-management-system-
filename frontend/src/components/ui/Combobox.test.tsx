import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { useState } from 'react';
import { Combobox } from './Combobox';
import type { ComboboxOption, ComboboxProps } from './Combobox';

const families: ComboboxOption[] = [
  { value: '1', label: 'Caminhões' },
  { value: '2', label: 'Escavadeiras' },
  { value: '3', label: 'Carregadeiras' },
];

function search(query: string) {
  return Promise.resolve(
    families.filter((f) => f.label.toLowerCase().includes(query.toLowerCase())),
  );
}

function Harness(props: Partial<ComboboxProps> & { initial?: ComboboxOption | null }) {
  const { initial = null, ...rest } = props;
  const [value, setValue] = useState<ComboboxOption | null>(initial);
  return (
    <>
      <label htmlFor="familia">Família</label>
      <Combobox
        id="familia"
        loadOptions={search}
        value={value}
        onChange={setValue}
        debounceMs={0}
        {...rest}
      />
    </>
  );
}

describe('Combobox', () => {
  it('abre ao focar, busca remotamente e tem área mínima de 48px', async () => {
    const user = userEvent.setup();
    const loadOptions = vi.fn(search);
    render(<Harness loadOptions={loadOptions} />);
    const input = screen.getByRole('combobox', { name: 'Família' });
    expect(input).toHaveClass('min-h-12', 'min-w-12');
    await user.click(input);
    expect(input).toHaveAttribute('aria-expanded', 'true');
    expect(await screen.findAllByRole('option')).toHaveLength(3);
    expect(loadOptions).toHaveBeenCalledWith('', expect.any(AbortSignal));
    screen.getAllByRole('option').forEach((o) => expect(o).toHaveClass('min-h-12'));
  });

  it('filtra pelo texto digitado e seleciona por teclado', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<Harness onChange={onChange} />);
    const input = screen.getByRole('combobox');
    await user.type(input, 'Carr');
    await waitFor(() => expect(screen.getAllByRole('option')).toHaveLength(1));
    expect(input).toHaveAttribute('aria-activedescendant', screen.getByRole('option').id);
    await user.keyboard('{Enter}');
    expect(onChange).toHaveBeenCalledWith({ value: '3', label: 'Carregadeiras' });
    expect(input).toHaveAttribute('aria-expanded', 'false');
  });

  it('setas movem a opção ativa e o clique seleciona', async () => {
    const user = userEvent.setup();
    render(<Harness />);
    const input = screen.getByRole('combobox');
    await user.click(input);
    const options = await screen.findAllByRole('option');
    expect(input).toHaveAttribute('aria-activedescendant', options[0]?.id);
    await user.keyboard('{ArrowDown}');
    expect(input).toHaveAttribute('aria-activedescendant', options[1]?.id);
    await user.keyboard('{ArrowUp}');
    expect(input).toHaveAttribute('aria-activedescendant', options[0]?.id);
    await user.click(screen.getByRole('option', { name: 'Escavadeiras' }));
    expect(input).toHaveValue('Escavadeiras');
  });

  it('ESC fecha e restaura o rótulo do valor atual', async () => {
    const user = userEvent.setup();
    render(<Harness initial={families[0]} />);
    const input = screen.getByRole('combobox');
    await user.clear(input);
    await user.type(input, 'xyz');
    await user.keyboard('{Escape}');
    expect(input).toHaveValue('Caminhões');
    expect(input).toHaveAttribute('aria-expanded', 'false');
  });

  it('reabrir após selecionar busca com query vazia', async () => {
    const user = userEvent.setup();
    const loadOptions = vi.fn(search);
    render(
      <>
        <Harness loadOptions={loadOptions} />
        <button type="button">fora</button>
      </>,
    );
    const input = screen.getByRole('combobox');
    await user.type(input, 'Carr');
    await waitFor(() => expect(screen.getAllByRole('option')).toHaveLength(1));
    await user.keyboard('{Enter}');
    expect(input).toHaveValue('Carregadeiras');

    await user.click(screen.getByRole('button', { name: 'fora' }));
    loadOptions.mockClear();
    await user.click(input);
    expect(await screen.findAllByRole('option')).toHaveLength(3);
    expect(loadOptions).toHaveBeenCalledTimes(1);
    expect(loadOptions).toHaveBeenCalledWith('', expect.any(AbortSignal));
  });

  it.each([
    ['blur', async (user: ReturnType<typeof userEvent.setup>) => user.tab()],
    ['Escape', async (user: ReturnType<typeof userEvent.setup>) => user.keyboard('{Escape}')],
  ])('após %s a próxima abertura busca com query vazia', async (_label, dismiss) => {
    const user = userEvent.setup();
    const loadOptions = vi.fn(search);
    render(<Harness loadOptions={loadOptions} />);
    const input = screen.getByRole('combobox');
    await user.type(input, 'cen');
    await screen.findByText('Nenhum resultado.');
    await dismiss(user);
    expect(input).toHaveAttribute('aria-expanded', 'false');
    loadOptions.mockClear();
    await user.click(input);
    expect(await screen.findAllByRole('option')).toHaveLength(3);
    expect(loadOptions).toHaveBeenLastCalledWith('', expect.any(AbortSignal));
  });

  it('Enter durante a busca não seleciona opção obsoleta; aviso fica no popup', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    let resolveNext: ((options: ComboboxOption[]) => void) | undefined;
    const loadOptions = vi.fn((query: string) =>
      query === '' ? search('') : new Promise<ComboboxOption[]>((r) => (resolveNext = r)),
    );
    render(<Harness loadOptions={loadOptions} onChange={onChange} />);
    const input = screen.getByRole('combobox');
    await user.click(input);
    await screen.findAllByRole('option');

    await user.type(input, 'Esc');
    expect(screen.getByRole('status')).toHaveTextContent('Carregando…');
    expect(screen.getByRole('listbox', { hidden: true })).toHaveAttribute('aria-busy', 'true');
    expect(input).not.toHaveAttribute('aria-activedescendant');
    await user.keyboard('{Enter}');
    expect(onChange).not.toHaveBeenCalled();

    await waitFor(() => expect(resolveNext).toBeDefined());
    resolveNext?.([families[1] as ComboboxOption]);
    await waitFor(() => expect(screen.getAllByRole('option')).toHaveLength(1));
    expect(screen.queryByText('Carregando…')).not.toBeInTheDocument();
    await user.keyboard('{Enter}');
    expect(onChange).toHaveBeenCalledWith(families[1]);
  });

  it('limpar seleção zera o valor', async () => {
    const user = userEvent.setup();
    render(<Harness initial={families[0]} />);
    const clear = screen.getByRole('button', { name: 'Limpar seleção' });
    expect(clear).toHaveClass('min-h-12', 'min-w-12');
    await user.click(clear);
    expect(screen.getByRole('combobox')).toHaveValue('');
    expect(screen.queryByRole('button', { name: 'Limpar seleção' })).not.toBeInTheDocument();
  });

  it('mostra "Nenhum resultado." e erro de busca', async () => {
    const user = userEvent.setup();
    const { unmount } = render(<Harness />);
    await user.type(screen.getByRole('combobox'), 'zzz');
    expect(await screen.findByText('Nenhum resultado.')).toBeInTheDocument();
    unmount();

    render(<Harness loadOptions={() => Promise.reject(new Error('rede'))} />);
    await user.click(screen.getByRole('combobox'));
    expect(await screen.findByRole('alert')).toHaveTextContent('Não foi possível buscar');
  });

  it('não tem violações de acessibilidade (aberto)', async () => {
    const user = userEvent.setup();
    const { container } = render(<Harness />);
    await user.click(screen.getByRole('combobox'));
    await screen.findAllByRole('option');
    expect(await axe(container)).toHaveNoViolations();
  });
});
