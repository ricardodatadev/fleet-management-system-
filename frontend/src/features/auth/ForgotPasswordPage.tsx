import { useState } from 'react';
import type { FormEvent } from 'react';
import { ApiError, GENERIC_ERROR_MESSAGE } from '@/api';
import { Button, FormField, Input } from '@/components/ui';
import { authApi } from './api';
import { AuthCard, AuthLink, FormAlert, FormNotice } from './AuthCard';
import { retryText, useCountdown } from './useCountdown';

/** Mensagem exibida se o envelope vier sem `message` (o backend sempre manda a genérica). */
export const FORGOT_FALLBACK_MESSAGE =
  'Se o e-mail estiver cadastrado, você receberá um link para redefinir a senha.';

/**
 * Esqueci a senha (v1.7): sempre a mesma resposta genérica, exista ou não o e-mail (o backend
 * não enumera usuários). Sem validação bloqueante no cliente (G.1).
 */
export function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [emailError, setEmailError] = useState<string | undefined>();
  const [formError, setFormError] = useState<string | null>(null);
  const [sentMessage, setSentMessage] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [lockedFor, setLockedFor] = useCountdown();
  const locked = lockedFor > 0;

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting || locked) return;
    setEmailError(undefined);
    setFormError(null);
    setSubmitting(true);
    try {
      const message = await authApi.forgotPassword(email.trim());
      setSentMessage(message || FORGOT_FALLBACK_MESSAGE);
    } catch (error) {
      if (!(error instanceof ApiError)) setFormError(GENERIC_ERROR_MESSAGE);
      else if (error.isTooManyRequests) {
        setLockedFor(error.retryAfter ?? 60);
        setFormError(error.message);
      } else if (error.isValidation && error.fieldMessage('email')) {
        setEmailError(error.fieldMessage('email'));
      } else setFormError(error.message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthCard
      title="Esqueceu sua senha?"
      description="Informe o e-mail da sua conta para receber um link de redefinição."
    >
      {sentMessage ? (
        <FormNotice>{sentMessage}</FormNotice>
      ) : (
        <>
          {formError && (
            <FormAlert message={formError} detail={locked ? retryText(lockedFor) : undefined} />
          )}
          <form noValidate onSubmit={onSubmit} className="flex flex-col gap-4">
            <FormField label="E-mail" required error={emailError}>
              <Input
                type="email"
                name="email"
                autoComplete="email"
                inputMode="email"
                // Tela de um campo só: o foco vai direto para ele.
                // eslint-disable-next-line jsx-a11y/no-autofocus
                autoFocus
                value={email}
                invalid={Boolean(emailError)}
                onChange={(event) => setEmail(event.target.value)}
              />
            </FormField>
            <Button type="submit" loading={submitting} disabled={locked} className="w-full">
              Enviar
            </Button>
          </form>
        </>
      )}
      <AuthLink to="/login" className="self-center">
        Voltar para o login
      </AuthLink>
    </AuthCard>
  );
}
