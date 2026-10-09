import { HomeLink } from './HomeLink';
import { StatusPage } from './StatusPage';

export function NotFoundPage() {
  return (
    <StatusPage
      code="404"
      title="Página não encontrada"
      description="O endereço acessado não existe ou foi movido."
      action={<HomeLink />}
    />
  );
}
