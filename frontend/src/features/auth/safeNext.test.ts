import { DEFAULT_AUTHENTICATED_PATH, loginPath, safeNext } from './safeNext';

describe('safeNext (mitigação de open redirect, ADR-0002)', () => {
  it.each(['/parametros', '/ativos/equipamentos?q=cm&page=2', '/ativos/equipamentos#topo'])(
    'aceita path relativo: %s',
    (next) => {
      expect(safeNext(next)).toBe(next);
    },
  );

  it.each([
    null,
    '',
    'parametros',
    'https://evil.example',
    '//evil.example',
    '/\\evil.example',
    '\\\\evil.example',
    '/ok\\..\\x',
    'javascript:alert(1)',
    '/\u0000x',
    '/\tevil',
    '/login',
    '/login?next=%2Fparametros',
  ])('rejeita %j e cai no padrão', (next) => {
    expect(safeNext(next)).toBe(DEFAULT_AUTHENTICATED_PATH);
  });
});

describe('loginPath', () => {
  it('codifica a rota atual (path + query + hash) em next', () => {
    expect(loginPath({ pathname: '/parametros', search: '?scope=branch', hash: '#x' })).toBe(
      '/login?next=%2Fparametros%3Fscope%3Dbranch%23x',
    );
  });

  it('sem next para a raiz ou o próprio login', () => {
    expect(loginPath({ pathname: '/' })).toBe('/login');
    expect(loginPath({ pathname: '/login' })).toBe('/login');
    expect(loginPath()).toBe('/login');
  });
});
