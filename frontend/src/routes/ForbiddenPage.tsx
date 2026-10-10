import { HomeLink } from './HomeLink';
import { StatusPage } from './StatusPage';

export function ForbiddenPage() {
  return (
    <StatusPage
      code="403"
      title="Acesso negado"
      description="Seu perfil não tem permissão para acessar esta página. Se precisar do acesso, procure o administrador."
      action={<HomeLink />}
    />
  );
}
