import { fireEvent, render, screen } from '@testing-library/react';
import { Avatar } from './Avatar';
import { initials } from './initials';

describe('Avatar', () => {
  it.each([
    ['Ana Souza', 'AS'],
    ['ana maria de souza', 'AS'],
    ['  Ícaro  ', 'Í'],
    ['', '?'],
  ])('initials(%j) = %s', (name, expected) => {
    expect(initials(name)).toBe(expected);
  });

  it('sem foto: iniciais, decorativo (aria-hidden)', () => {
    render(<Avatar name="Ana Souza" />);
    const avatar = screen.getByTestId('avatar');
    expect(avatar).toHaveTextContent('AS');
    expect(avatar).toHaveAttribute('aria-hidden', 'true');
    expect(avatar).toHaveClass('rounded-full', 'bg-brand-subtle', 'text-brand-pressed');
  });

  it('com foto: mostra a imagem; se falhar ao carregar, volta às iniciais', () => {
    render(<Avatar name="Ana Souza" src="/fotos/ana.jpg" />);
    const img = screen.getByTestId('avatar').querySelector('img') as HTMLImageElement;
    expect(img).toHaveAttribute('src', '/fotos/ana.jpg');
    expect(img).toHaveAttribute('alt', '');
    fireEvent.error(img);
    expect(screen.getByTestId('avatar')).toHaveTextContent('AS');
    expect(screen.getByTestId('avatar').querySelector('img')).toBeNull();
  });
});
