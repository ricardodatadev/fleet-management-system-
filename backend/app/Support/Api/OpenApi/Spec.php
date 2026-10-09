<?php

namespace App\Support\Api\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Definições globais da OpenAPI (info, servidor, segurança, schemas e respostas reutilizáveis).
 * Os endpoints são anotados nos próprios controllers.
 */
#[OA\Info(
    version: '0.1.0',
    title: 'SIGOF-M API',
    license: new OA\License(name: 'Proprietary'),
    description: 'API REST do SIGOF-M (Sistema Integrado de Gestão e Otimização de Frota e Manutenção). Todas as respostas, inclusive erros, usam o envelope {status, message, errors, data[, meta]}.',
)]
#[OA\Server(url: '/api/v1', description: 'API v1 (mesma origem, via nginx)')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum personal access token',
    description: 'Token obtido em POST /auth/login (expira em 12 h).',
)]
#[OA\Schema(
    schema: 'Envelope',
    description: 'Envelope padrão de toda resposta.',
    required: ['status', 'message', 'errors', 'data'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['success', 'error']),
        new OA\Property(property: 'message', type: 'string', example: 'Operação realizada com sucesso.'),
        new OA\Property(property: 'errors', type: 'object', nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string'))),
        new OA\Property(property: 'data', description: 'Objeto, lista ou null conforme o endpoint (qualquer tipo).'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/Pagination'),
    ],
)]
#[OA\Schema(
    schema: 'Pagination',
    description: 'Presente (`meta`) apenas em listas paginadas.',
    required: ['current_page', 'per_page', 'total', 'last_page'],
    properties: [
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'per_page', type: 'integer', example: 15),
        new OA\Property(property: 'total', type: 'integer', example: 0),
        new OA\Property(property: 'last_page', type: 'integer', example: 1),
    ],
)]
#[OA\Schema(
    schema: 'ErrorEnvelope',
    description: 'Resposta de erro (401, 403, 404, 405, 409, 429, 500, 503).',
    required: ['status', 'message', 'errors', 'data'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['error']),
        new OA\Property(property: 'message', type: 'string', example: 'Recurso não encontrado.'),
        new OA\Property(property: 'errors', type: 'object', nullable: true, example: null),
        new OA\Property(property: 'data', description: 'Sempre null em erros.', example: null),
    ],
)]
#[OA\Schema(
    schema: 'ValidationErrorEnvelope',
    description: 'Erro de validação (422): `errors` mapeia campo → mensagens.',
    required: ['status', 'message', 'errors', 'data'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['error']),
        new OA\Property(property: 'message', type: 'string', example: 'Os dados informados são inválidos.'),
        new OA\Property(
            property: 'errors',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string')),
            example: ['email' => ['O campo email é obrigatório.']],
        ),
        new OA\Property(property: 'data', description: 'Sempre null em erros.', example: null),
    ],
)]
#[OA\Schema(
    schema: 'DeleteConflictEnvelope',
    description: 'Exclusão bloqueada por dependentes ativos (409): `errors.dependents` lista os tipos.',
    required: ['status', 'message', 'errors', 'data'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['error']),
        new OA\Property(property: 'message', type: 'string', example: 'Não é possível excluir: existem registros ativos vinculados (centros de custo).'),
        new OA\Property(property: 'errors', type: 'object', nullable: true, example: ['dependents' => ['cost_centers']], additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string'))),
        new OA\Property(property: 'data', example: null),
    ],
)]
#[OA\Response(response: 'Unauthenticated', description: 'Não autenticado (401).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope'))]
#[OA\Response(response: 'Forbidden', description: 'Acesso negado (403).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope'))]
#[OA\Response(response: 'NotFound', description: 'Recurso não encontrado (404).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope'))]
#[OA\Response(response: 'Conflict', description: 'Conflito de regra de negócio (409).', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope'))]
#[OA\Response(response: 'ValidationError', description: 'Dados inválidos (422).', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorEnvelope'))]
#[OA\Response(
    response: 'TooManyRequests',
    description: 'Limite de requisições excedido (429).',
    headers: [new OA\Header(header: 'Retry-After', description: 'Segundos até poder tentar novamente.', schema: new OA\Schema(type: 'integer'))],
    content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope'),
)]
#[OA\Response(response: 'ServerError', description: 'Erro interno (500), sem detalhes técnicos com APP_DEBUG=false.', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope'))]
final class Spec {}
