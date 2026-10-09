import { useState } from 'react';
import type { FormEvent } from 'react';
import { useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { ApiError, GENERIC_ERROR_MESSAGE } from '@/api';
import { Button, FormField, Input } from '@/components/ui';
import { AuthCard, AuthLink, FormAlert, FormNotice } from './AuthCard';
import { useAuth } from './auth-context';
import type { SignedOutReason } from './auth-context';
import { PasswordInput } from './PasswordInput';
import { safeNext } from './safeNext';
import { retryText, useCountdown } from './useCountdown';

const notices: Partial<Record<SignedOutReason, string>> = {
  expired: 'Sua sessão expirou. Entre novamente.',
  unauthorized: 'Sua sessão não é mais válida. Entre novamente.',
};

/** Estado de navegação aceito pelo login (ex.: aviso de senha redefinida). */
export interface LoginLocationState {
  notice?: string;
}

interface FieldErrors {
  username?: string;
  password?: string;
}

/**
 * Login por usuário (v1.8; o e-mail serve só para recuperar a senha). Sem validação bloqueante
 * no cliente: a obrigatoriedade vem do 422 por campo do backend (G.1).
 */
export function LoginPage() {
  const auth = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [params] = useSearchParams();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [lockedFor, setLockedFor] = useCountdown();

  const stateNotice = (location.state as LoginLocationState | null)?.notice;
  const notice = stateNotice ?? (auth.status === 'anonymous' ? notices[auth.reason] : undefined);
  const locked = lockedFor > 0;

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting || locked) return;
    setFieldErrors({});
    setFormError(null);
    setSubmitting(true);
    // Sem normalizar: trim e minúsculas são do backend (v1.8).
    const credentials = { username, password };
    try {
      await auth.login(credentials);
      navigate(safeNext(params.get('next')), { replace: true });
    } catch (error) {
      setSubmitting(false);
      if (!(error instanceof ApiError)) {
        setFormError(GENERIC_ERROR_MESSAGE);
        return;
      }
      if (error.isTooManyRequests) {
        setLockedFor(error.retryAfter ?? 60);
        setFormError(error.message);
        return;
      }
      if (error.isValidation) {
        // Com os dois campos preenchidos (o backend faz trim), o 422 é a credencial inválida,
        // genérica: vai para o topo, sem acusar um campo. Senão é obrigatoriedade, por campo.
        const filled = username.trim() !== '' && password !== '';
        if (filled && !error.fieldMessage('password')) {
          setFormError(error.fieldMessage('username') ?? error.message);
          return;
        }
        setFieldErrors({
          username: error.fieldMessage('username'),
          password: error.fieldMessage('password'),
        });
        return;
      }
      setFormError(error.message); // 403 inativo, rede, 500: mensagem do envelope/cliente
    }
  }

  return (
    <AuthCard title="Entrar">
      {notice && !formError && <FormNotice>{notice}</FormNotice>}
      {formError && (
        <FormAlert message={formError} detail={locked ? retryText(lockedFor) : undefined} />
      )}

      <form noValidate onSubmit={onSubmit} className="flex flex-col gap-4">
        <FormField label="Usuário" required hideRequiredMark error={fieldErrors.username}>
          <Input
            name="username"
            autoComplete="username"
            autoCapitalize="none"
            autoCorrect="off"
            spellCheck={false}
            // Foco automático exigido pela spec (G.4-1); é o primeiro campo da tela.
            // eslint-disable-next-line jsx-a11y/no-autofocus
            autoFocus
            value={username}
            invalid={Boolean(fieldErrors.username)}
            onChange={(event) => setUsername(event.target.value)}
          />
        </FormField>

        <FormField label="Senha" required hideRequiredMark error={fieldErrors.password}>
          {(control) => (
            <PasswordInput
              {...control}
              name="password"
              autoComplete="current-password"
              value={password}
              invalid={Boolean(fieldErrors.password)}
              onChange={(event) => setPassword(event.target.value)}
            />
          )}
        </FormField>

        <Button type="submit" loading={submitting} disabled={locked} className="w-full">
          Entrar
        </Button>
      </form>

      <AuthLink to="/esqueci-senha" className="self-center">
        Esqueceu sua senha?
      </AuthLink>
    </AuthCard>
  );
}
