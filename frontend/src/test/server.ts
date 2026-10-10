import { HttpResponse, http } from 'msw';
import { setupServer } from 'msw/node';

const emptyPage = () =>
  HttpResponse.json({
    status: 'success',
    message: 'OK',
    errors: null,
    data: [],
    meta: { current_page: 1, per_page: 15, total: 0, last_page: 1 },
  });

/**
 * Padrões da tela inicial (/ativos/equipamentos, F1-25) e do Painel de Parâmetros (F1-28): muitos
 * testes passam por elas só para chegar ao shell. Lista e lookups vazios e enums mínimos; cada teste sobrescreve com
 * `server.use(...)`. Qualquer outra rota sem handler continua falhando (onUnhandledRequest).
 */
export const defaultHandlers = [
  http.get('*/api/v1/equipments', emptyPage),
  http.get('*/api/v1/branches', emptyPage),
  http.get('*/api/v1/equipment-families', emptyPage),
  // Lookups do formulário de equipamento (F1-26), aberto a partir da lista.
  http.get('*/api/v1/cost-centers', emptyPage),
  http.get('*/api/v1/employees', emptyPage),
  // Painel de Parâmetros (/parametros, F1-28): sem definições por padrão.
  http.get('*/api/v1/settings/definitions', () =>
    HttpResponse.json({ status: 'success', message: 'OK', errors: null, data: [] }),
  ),
  http.get('*/api/v1/meta/enums', () =>
    HttpResponse.json({
      status: 'success',
      message: 'OK',
      errors: null,
      data: { enums: { equipment_statuses: ['active', 'inactive', 'disposed'] } },
    }),
  ),
];

/** Servidor MSW compartilhado; handlers por teste via `server.use(...)`. */
export const server = setupServer(...defaultHandlers);
