import { isRouteErrorResponse, useRouteError } from 'react-router-dom';
import { Button } from '@/components/ui';
import { NotFoundPage } from './NotFoundPage';
import { StatusPage } from './StatusPage';

/** ErrorBoundary global das rotas (spec G.4-4): nunca mostra stack ao usuário. */
export function RouteErrorPage() {
  const error = useRouteError();
  if (isRouteErrorResponse(error) && error.status === 404) return <NotFoundPage />;
  return (
    <StatusPage
      code="Erro"
      title="Algo deu errado"
      description="Ocorreu um erro inesperado ao exibir esta página. Recarregue para tentar novamente."
      action={<Button onClick={() => window.location.reload()}>Recarregar</Button>}
    />
  );
}
