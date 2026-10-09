<?php

namespace App\Support\Api\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parâmetros reutilizáveis das listas (convenções da D.1). Ficam numa classe própria: no swagger-php,
 * na classe Spec, os Parameter faziam a geração perder os schemas dela.
 */
#[OA\Parameter(parameter: 'Page', name: 'page', in: 'query', description: 'Página (1..n).', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1))]
#[OA\Parameter(parameter: 'PerPage', name: 'per_page', in: 'query', description: 'Itens por página: máx. 100; até 200 com `is_active=1` (selects).', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200, default: 15))]
#[OA\Parameter(parameter: 'Search', name: 'q', in: 'query', description: 'Busca textual (contém, sem diferenciar maiúsculas; `%` e `_` são literais).', schema: new OA\Schema(type: 'string', maxLength: 100))]
#[OA\Parameter(parameter: 'WithTrashed', name: 'with_trashed', in: 'query', description: 'Inclui registros excluídos (soft delete). Exige `*.manage` do recurso; sem ela → 403.', schema: new OA\Schema(type: 'integer', enum: [0, 1]))]
#[OA\Parameter(parameter: 'IsActive', name: 'is_active', in: 'query', description: 'Filtra por ativo (1) / inativo (0).', schema: new OA\Schema(type: 'integer', enum: [0, 1]))]
final class ListParameters {}
