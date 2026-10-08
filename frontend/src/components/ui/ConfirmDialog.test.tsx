import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { useState } from 'react';
import type { ComponentProps } from 'react';
import { Button } from './Button';
import { ConfirmDialog } from './ConfirmDialog';

function renderDialog(props: Partial<ComponentProps<typeof ConfirmDialog>> = {}) {
  const onConfirm = vi.fn();
  const onOpenChange = vi.fn();
  render(
    <ConfirmDialog
      open
      onOpenChange={onOpenChange}
      title="Excluir frota?"
      description="Esta ação não pode ser desfeita."
      confirmLabel="Excluir"
      destructive
      onConfirm={onConfirm}
      {...props}
    />,
  );
  return { onConfirm, onOpenChange };
}

describe('ConfirmDialog', () => {
  it('é um alertdialog com título e descrição', () => {
    renderDialog();
    const dialog = screen.getByRole('alertdialog', { name: 'Excluir frota?' });
    expect(dialog).toHaveAccessibleDescription('Esta ação não pode ser desfeita.');
    expect(screen.getByRole('button', { name: 'Excluir' })).toHaveClass('bg-danger-600');
  });

  it('foca Cancelar ao abrir; confirmar chama onConfirm sem fechar', async () => {
    const user = userEvent.setup();
    const { onConfirm, onOpenChange } = renderDialog();
    expect(screen.getByRole('button', { name: 'Cancelar' })).toHaveFocus();
    await user.click(screen.getByRole('button', { name: 'Excluir' }));
    expect(onConfirm).toHaveBeenCalledTimes(1);
    expect(onOpenChange).not.toHaveBeenCalled();
  });

  it('Cancelar e ESC pedem fechamento', async () => {
    const user = userEvent.setup();
    const { onOpenChange } = renderDialog();
    await user.click(screen.getByRole('button', { name: 'Cancelar' }));
    expect(onOpenChange).toHaveBeenCalledWith(false);
    onOpenChange.mockClear();
    await user.keyboard('{Escape}');
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });

  it('ao fechar devolve o foco a quem abriu', async () => {
    const user = userEvent.setup();
    function Harness() {
      const [open, setOpen] = useState(false);
      return (
        <>
          <Button onClick={() => setOpen(true)}>Excluir CM-01</Button>
          <ConfirmDialog
            open={open}
            onOpenChange={setOpen}
            title="Excluir frota?"
            description="Esta ação não pode ser desfeita."
            onConfirm={() => setOpen(false)}
          />
        </>
      );
    }
    render(<Harness />);
    const trigger = screen.getByRole('button', { name: 'Excluir CM-01' });
    await user.click(trigger);
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    await waitFor(() => expect(trigger).toHaveFocus());
  });

  it('mostra erro do envelope (409) e estado loading', () => {
    renderDialog({ error: 'Equipamento possui OS vinculadas.', loading: true });
    expect(screen.getByRole('alert')).toHaveTextContent('Equipamento possui OS vinculadas.');
    expect(screen.getByRole('button', { name: 'Excluir' })).toHaveAttribute('aria-busy', 'true');
    expect(screen.getByRole('button', { name: 'Cancelar' })).toBeDisabled();
  });

  it('não tem violações de acessibilidade', async () => {
    renderDialog({ error: 'Conflito' });
    expect(await axe(document.body)).toHaveNoViolations();
  });
});
