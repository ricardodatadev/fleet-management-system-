import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import type { LinkProps } from 'react-router-dom';
import { buttonBase, buttonVariants } from '@/components/ui/styles';
import { APP_FULL_NAME, APP_NAME } from '@/config/brand';
import { cn } from '@/lib/cn';
import { TAP_MIN_CLASSES } from '@/lib/tokens';

/** Casca das telas públicas (login, esqueci e redefinir senha): cartão centrado com a marca. */
export function AuthCard({
  title,
  description = APP_FULL_NAME,
  children,
}: {
  title: string;
  description?: string;
  children: ReactNode;
}) {
  return (
    <main className="flex min-h-screen items-center justify-center bg-bg p-4">
      <div className="flex w-full max-w-md flex-col gap-6 rounded-card bg-surface p-6 shadow-lg sm:p-8">
        <div className="flex flex-col gap-1 text-center">
          <p className="text-sm font-bold tracking-wide text-brand uppercase">{APP_NAME}</p>
          <h1 className="text-2xl font-bold text-text">{title}</h1>
          <p className="text-text-muted">{description}</p>
        </div>
        {children}
      </div>
    </main>
  );
}

/** Link de navegação das telas públicas, com alvo ≥ 48px. */
export function AuthLink({ className, ...props }: LinkProps) {
  return (
    <Link
      {...props}
      className={cn(TAP_MIN_CLASSES, buttonBase, buttonVariants.ghost, 'underline', className)}
    />
  );
}

/** Erro do formulário (envelope); `detail` = linha extra (ex.: contagem do 429). */
export function FormAlert({ message, detail }: { message: string; detail?: ReactNode }) {
  return (
    <div role="alert" className="rounded-control bg-danger-subtle p-3 text-danger">
      <p className="font-bold">{message}</p>
      {detail && <div className="text-sm">{detail}</div>}
    </div>
  );
}

/** Aviso neutro/positivo (sessão expirada, senha redefinida, e-mail enviado). */
export function FormNotice({ children }: { children: ReactNode }) {
  return (
    <div role="status" className="rounded-control bg-info-subtle p-3 text-info">
      {children}
    </div>
  );
}
