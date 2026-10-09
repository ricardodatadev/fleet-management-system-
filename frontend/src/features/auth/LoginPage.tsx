import { Eye, EyeOff } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { ApiError, GENERIC_ERROR_MESSAGE } from '@/api';
import { Button, FormField, IconButton, Input } from '@/components/ui';
import { APP_FULL_NAME, APP_NAME } from '@/config/brand';
import { useAuth } from './auth-context';
import type { SignedOutReason } from './auth-context';
import { safeNext } from './safeNext';

const notices: Partial<Record<SignedOutReason, string>> = {
  expired: 'Sua sessão expirou. Entre novamente.',
  unauthorized: 'Sua sessão não é mais válida. Entre novamente.',
};

interface FieldErrors {
  email?: string;
  password?: string;
}

/** Contagem regressiva do bloqueio por 429 (Retry-After). */
function useCountdown() {
  const [seconds, setSeconds] = useState(0);
  useEffect(() => {
    if (seconds <= 0) return;
    const timer = setTimeout(() => setSeconds((s) => s - 1), 1000);
    return () => clearTimeout(timer);
  }, [seconds]);
  return [seconds, setSeconds] as const;
}

export function LoginPage() {
  const auth = useAuth();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [lockedFor, setLockedFor] = useCountdown();

  const notice = auth.status === 'anonymous' ? notices[auth.reason] : undefined;
  const locked = lockedFor > 0;

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting || locked) return;

    const errors: FieldErrors = {};
    if (!email.trim()) errors.email = 'Informe o e-mail.';
    if (!password) errors.password = 'Informe a senha.';
    setFieldErrors(errors);
    setFormError(null);
    if (errors.email || errors.password) return;

    setSubmitting(true);
    try {
      await auth.login({ email: email.trim(), password });
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
        // Credencial inválida vem genérica em errors.email; é exibida como erro do formulário.
        setFormError(error.fieldMessage('email') ?? error.message);
        setFieldErrors({ password: error.fieldMessage('password') });
        return;
      }
      setFormError(error.message); // 403 inativo, rede, 500: mensagem do envelope/cliente
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center bg-surface-muted p-4">
      <div className="flex w-full max-w-md flex-col gap-6 rounded-xl bg-surface p-6 shadow-lg sm:p-8">
        <div className="flex flex-col gap-1 text-center">
          <p className="text-sm font-bold tracking-wide text-brand-600 uppercase">{APP_NAME}</p>
          <h1 className="text-2xl font-bold text-ink">Entrar</h1>
          <p className="text-ink-muted">{APP_FULL_NAME}</p>
        </div>

        {notice && !formError && (
          <p role="status" className="rounded-lg bg-blue-50 p-3 text-blue-950">
            {notice}
          </p>
        )}
        {formError && (
          <div role="alert" className="rounded-lg bg-red-50 p-3 text-red-900">
            <p className="font-medium">{formError}</p>
            {locked && (
              <p className="text-sm">
                Tente novamente em {lockedFor} {lockedFor === 1 ? 'segundo' : 'segundos'}.
              </p>
            )}
          </div>
        )}

        <form noValidate onSubmit={onSubmit} className="flex flex-col gap-4">
          <FormField label="E-mail" required error={fieldErrors.email}>
            <Input
              type="email"
              name="email"
              autoComplete="username"
              inputMode="email"
              // Foco automático exigido pela spec (G.4-1); é o único campo de entrada da tela.
              // eslint-disable-next-line jsx-a11y/no-autofocus
              autoFocus
              value={email}
              invalid={Boolean(fieldErrors.email)}
              onChange={(event) => setEmail(event.target.value)}
            />
          </FormField>

          <FormField label="Senha" required error={fieldErrors.password}>
            {(control) => (
              <div className="flex items-center gap-2">
                <Input
                  {...control}
                  type={showPassword ? 'text' : 'password'}
                  name="password"
                  autoComplete="current-password"
                  value={password}
                  invalid={Boolean(fieldErrors.password)}
                  onChange={(event) => setPassword(event.target.value)}
                />
                <IconButton
                  label={showPassword ? 'Ocultar senha' : 'Mostrar senha'}
                  aria-pressed={showPassword}
                  variant="secondary"
                  icon={showPassword ? <EyeOff /> : <Eye />}
                  onClick={() => setShowPassword((v) => !v)}
                />
              </div>
            )}
          </FormField>

          <Button type="submit" loading={submitting} disabled={locked} className="w-full">
            Entrar
          </Button>
        </form>
      </div>
    </main>
  );
}
