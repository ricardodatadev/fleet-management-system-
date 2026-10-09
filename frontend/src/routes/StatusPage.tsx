import type { ReactNode } from 'react';

/** Página cheia para 403/404/erro global. */
export function StatusPage({
  code,
  title,
  description,
  action,
}: {
  code: string;
  title: string;
  description: string;
  action: ReactNode;
}) {
  return (
    <main className="flex min-h-screen flex-col items-center justify-center gap-4 p-4 text-center">
      <p aria-hidden="true" className="text-6xl font-bold text-brand-600">
        {code}
      </p>
      <h1 className="text-2xl font-bold text-ink">{title}</h1>
      <p className="max-w-prose text-ink-muted">{description}</p>
      {action}
    </main>
  );
}
