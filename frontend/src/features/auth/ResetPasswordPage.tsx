import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { ApiError, GENERIC_ERROR_MESSAGE } from '@/api';
import { Button, FormField } from '@/components/ui';
import { authApi } from './api';
import { AuthCard, AuthLink, FormAlert } from './AuthCard';
import type { LoginLocationState } from './LoginPage';
import { PasswordInput } from './PasswordInput';
import { readResetLink } from './resetLink';
import { retryText, useCountdown } from './useCountdown';

export const INVALID_LINK_MESSAGE =
  'Link inválido ou expirado. Solicite uma nova redefinição de senha.';
export const RESET_SUCCESS_FALLBACK = 'Senha redefinida. Entre com a nova senha.';
export const PASSWORD_POLICY_HELP = 'Mínimo de 10 caracteres, com maiúscula, minúscula e número.';

/**
 * Redefinir senha (v1.7a). O link do e-mail traz `#token=…&email=…` no fragmento: a tela guarda
 * os dois e limpa o fragmento na abertura (replace no histórico), para não ficarem no histórico.
 * A query string não é lida (um link antigo com `?token=` cai no aviso de link inválido).
 */
export function ResetPasswordPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const [link] = useState(() => readResetLink(location.hash));
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [passwordError, setPasswordError] = useState<string | undefined>();
  const [confirmationError, setConfirmationError] = useState<string | undefined>();
  const [formError, setFormError] = useState<string | null>(null);
  const [invalidLink, setInvalidLink] = useState(!link.token || !link.email);
  const [submitting, setSubmitting] = useState(false);
  const [lockedFor, setLockedFor] = useCountdown();
  const locked = lockedFor > 0;

  // Replace pelo React Router: no browser é history.replaceState, e o roteador fica em sincronia.
  // Também tira uma query antiga (`?token=`) que, mesmo não lida, não deve ficar no histórico.
  useEffect(() => {
    if (location.hash || location.search) {
      navigate({ pathname: location.pathname, search: '', hash: '' }, { replace: true });
    }
  }, [location.pathname, location.hash, location.search, navigate]);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting || locked) return;
    setPasswordError(undefined);
    setConfirmationError(undefined);
    setFormError(null);
    setSubmitting(true);
    try {
      const message = await authApi.resetPassword({
        email: link.email,
        token: link.token,
        password,
        password_confirmation: confirmation,
      });
      const state: LoginLocationState = { notice: message || RESET_SUCCESS_FALLBACK };
      navigate('/login', { replace: true, state });
    } catch (error) {
      setSubmitting(false);
      if (!(error instanceof ApiError)) {
        setFormError(GENERIC_ERROR_MESSAGE);
      } else if (error.isTooManyRequests) {
        setLockedFor(error.retryAfter ?? 60);
        setFormError(error.message);
      } else if (
        error.isValidation &&
        (error.fieldMessage('password') || error.fieldMessage('password_confirmation'))
      ) {
        // A política de senha é checada antes do token: corrigir a senha primeiro.
        setPasswordError(error.fieldMessage('password'));
        setConfirmationError(error.fieldMessage('password_confirmation'));
      } else if (error.isValidation) {
        // errors.token/email: link inválido, expirado, usado ou de usuário inativo.
        setInvalidLink(true);
      } else {
        setFormError(error.message);
      }
    }
  }

  if (invalidLink) {
    return (
      <AuthCard title="Redefinir senha">
        <FormAlert message={INVALID_LINK_MESSAGE} />
        <AuthLink to="/esqueci-senha" className="self-center">
          Pedir novo link
        </AuthLink>
        <AuthLink to="/login" className="self-center">
          Voltar para o login
        </AuthLink>
      </AuthCard>
    );
  }

  return (
    <AuthCard title="Redefinir senha" description={`Crie uma nova senha para ${link.email}.`}>
      {formError && (
        <FormAlert message={formError} detail={locked ? retryText(lockedFor) : undefined} />
      )}
      <form noValidate onSubmit={onSubmit} className="flex flex-col gap-4">
        <FormField
          label="Nova senha"
          required
          hideRequiredMark
          help={PASSWORD_POLICY_HELP}
          error={passwordError}
        >
          {(control) => (
            <PasswordInput
              {...control}
              name="password"
              autoComplete="new-password"
              // eslint-disable-next-line jsx-a11y/no-autofocus
              autoFocus
              value={password}
              invalid={Boolean(passwordError)}
              onChange={(event) => setPassword(event.target.value)}
            />
          )}
        </FormField>
        <FormField label="Confirmar nova senha" required hideRequiredMark error={confirmationError}>
          {(control) => (
            <PasswordInput
              {...control}
              name="password_confirmation"
              autoComplete="new-password"
              value={confirmation}
              invalid={Boolean(confirmationError)}
              onChange={(event) => setConfirmation(event.target.value)}
            />
          )}
        </FormField>
        <Button type="submit" loading={submitting} disabled={locked} className="w-full">
          Redefinir senha
        </Button>
      </form>
      <AuthLink to="/login" className="self-center">
        Voltar para o login
      </AuthLink>
    </AuthCard>
  );
}
