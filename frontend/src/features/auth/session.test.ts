import { SESSION_STORAGE_KEY, clearSession, readSession, writeSession } from './session';

const NOW = Date.parse('2026-10-08T12:00:00Z');

describe('sessão em localStorage', () => {
  it('grava e lê token + expiração', () => {
    writeSession({ token: 't1', expiresAt: '2026-10-09T00:00:00Z' });
    expect(JSON.parse(localStorage.getItem(SESSION_STORAGE_KEY) ?? 'null')).toEqual({
      token: 't1',
      expiresAt: '2026-10-09T00:00:00Z',
    });
    expect(readSession(NOW)).toEqual({ token: 't1', expiresAt: '2026-10-09T00:00:00Z' });
  });

  it('sessão expirada é descartada e removida', () => {
    writeSession({ token: 't1', expiresAt: '2026-10-08T11:59:59Z' });
    expect(readSession(NOW)).toBeNull();
    expect(localStorage.getItem(SESSION_STORAGE_KEY)).toBeNull();
  });

  it('valor corrompido é descartado', () => {
    localStorage.setItem(SESSION_STORAGE_KEY, '{nao-json');
    expect(readSession(NOW)).toBeNull();
    localStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify({ token: 1 }));
    expect(readSession(NOW)).toBeNull();
    expect(localStorage.getItem(SESSION_STORAGE_KEY)).toBeNull();
  });

  it('clearSession remove a chave', () => {
    writeSession({ token: 't1', expiresAt: '2026-10-09T00:00:00Z' });
    clearSession();
    expect(readSession(NOW)).toBeNull();
  });

  it('storage indisponível não quebra', () => {
    const spy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('SecurityError');
    });
    expect(readSession(NOW)).toBeNull();
    spy.mockRestore();
  });
});
