/**
 * `token` e `email` do link de redefinição (v1.7a), que vêm no fragmento (`#token=…&email=…`):
 * o fragmento não vai ao servidor nem no referrer. `URLSearchParams` sobre o hash sem o `#`.
 */
export function readResetLink(hash: string): { token: string; email: string } {
  const params = new URLSearchParams(hash.replace(/^#/, ''));
  return { token: params.get('token') ?? '', email: params.get('email') ?? '' };
}
