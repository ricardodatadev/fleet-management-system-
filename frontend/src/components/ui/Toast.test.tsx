import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'jest-axe';
import { Button } from './Button';
import { ToastProvider } from './Toast';
import { useToast } from './toast-context';
import type { ToastOptions } from './toast-context';

function Trigger(options: ToastOptions) {
  const { toast } = useToast();
  return <Button onClick={() => toast(options)}>Disparar</Button>;
}

function renderToast(options: ToastOptions) {
  return render(
    <ToastProvider>
      <Trigger {...options} />
    </ToastProvider>,
  );
}

describe('Toast', () => {
  afterEach(() => {
    vi.useRealTimers();
  });

  it('mostra toast de sucesso em região status e fecha pelo botão', async () => {
    const user = userEvent.setup();
    renderToast({ title: 'Frota salva', description: 'Registrado em auditoria', tone: 'success' });
    await user.click(screen.getByRole('button', { name: 'Disparar' }));
    expect(screen.getByRole('status')).toHaveTextContent('Frota salva');
    expect(screen.getByRole('region', { name: 'Notificações' })).toHaveAttribute(
      'aria-live',
      'polite',
    );
    await user.click(screen.getByRole('button', { name: 'Fechar notificação' }));
    expect(screen.queryByText('Frota salva')).not.toBeInTheDocument();
  });

  it('toast de erro usa role="alert"', async () => {
    const user = userEvent.setup();
    renderToast({ title: 'Falhou', tone: 'danger' });
    await user.click(screen.getByRole('button', { name: 'Disparar' }));
    expect(screen.getByRole('alert')).toHaveTextContent('Falhou');
  });

  it('fecha sozinho após a duração', async () => {
    vi.useFakeTimers();
    renderToast({ title: 'Some', duration: 1000 });
    act(() => {
      screen.getByRole('button', { name: 'Disparar' }).click();
    });
    expect(screen.getByText('Some')).toBeInTheDocument();
    act(() => {
      vi.advanceTimersByTime(1000);
    });
    expect(screen.queryByText('Some')).not.toBeInTheDocument();
  });

  it('useToast fora do provider lança erro', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    expect(() => render(<Trigger title="x" />)).toThrow(/ToastProvider/);
    spy.mockRestore();
  });

  it('não tem violações de acessibilidade', async () => {
    const user = userEvent.setup();
    const { container } = renderToast({ title: 'Info' });
    await user.click(screen.getByRole('button', { name: 'Disparar' }));
    expect(await axe(container)).toHaveNoViolations();
  });
});
