# ADR-0001 — Desvios da especificação e decisões da Fase 1

- **Status:** aceito (spec Fase 1 v1.1, aprovada pelo Claudão; Fase 1 liberada pelo usuário)
- **Data:** 2026-10-08

## Contexto

A SRS v2.0.0 e a Especificação Técnica 01 v1.0.0 contêm itens que a Fase 1 intencionalmente não segue ao pé da letra. Este ADR registra cada desvio (D1–D16).

## Decisões

| # | Spec original | Decisão na Fase 1 |
|---|---|---|
| D1 | Laravel 11 / PHP 8.3 | Última versão estável do Laravel e o PHP mínimo/recomendado por ela. Se Horizon/Sanctum/l5-swagger/Pest não suportarem a versão, parar e acionar o Mr. Brain. |
| D2 | Sanctum "(OAuth2)" | Sanctum não é OAuth2. Fase 1 = Sanctum com Bearer personal access tokens (expiração 12 h). OIDC (RNF-005) fica como evolução; AES-256 em repouso e TLS 1.3 são responsabilidade da infra de produção (borda/LB, disco), fora do compose. |
| D3 | RNF-007 microsserviços/K8s | Monólito modular + 2 serviços (python, node). Somente Docker Compose; K8s fora do MVP. |
| D4 | Portas 5432/6379/8000/3000 no compose | Compose base publica só o nginx (`${WEB_HTTP_PORT:-80}`). Portas de dev (127.0.0.1) apenas em `docker-compose.override.yml`. |
| D5 | senha literal hardcoded no compose | Tudo via `${VAR}` do `.env` (sem default de senha no compose); `.env.example` com `change-me`. Redis com `requirepass`. |
| D6 | `version: '3.8'` | Removido. `name: sigof` no topo. |
| D7 | `docker/python-ai`, `docker/node-realtime` | Código em `services/python-ai` e `services/node-realtime` (Dockerfile em cada serviço). `docker/` guarda só nginx, laravel, postgres. |
| D8 | Nomes `sigof-*` + `container_name` | Serviços `nginx, app, worker, db, redis, python, node` (+ `frontend` só no override). Sem `container_name`. |
| D9 | "JSON:API" | Envelope próprio (`status, message, errors, data, meta`), não a spec JSON:API. |
| D10 | Redis / RabbitMQ | Somente Redis (filas, cache, sessão, pub/sub). RabbitMQ removido. |
| D11 | Node 20 | **Node 24 LTS** (decisão do Claudão em 2026-10-08) para `node` e build do frontend. A 26 só vira LTS em 2026-10-20; revisar em fase futura. Python 3.12, PostgreSQL 16, Redis 7, React 18 mantidos. |
| D12 | Horizon | `/horizon` não exposto pelo nginx na Fase 1; verificação via `horizon:status`. |
| D13 | Papéis Almoxarife/Financeiro (matriz §5 SRS) | Fora de RN-007; ficam para fase própria. Enum de perfis extensível. |
| D14 | RN-002 (matriz por Categoria de Serviço) | `workorder.block_close_without_labor` é placeholder (bool global/filial); a RN-002 real será modelada na fase de OS, substituindo a chave. RN-001 "Modo de Disparo" está adequada. |
| D15 | Token Bearer em `localStorage` | Aprovado na Fase 1 com CSP estrita. **Risco:** XSS pode roubar o token. Revisão obrigatória na fase do PWA/offline (avaliar cookie httpOnly/OIDC). |
| D16 | Git | Repositório e fluxo pertencem ao Escrivão. Senior/Íris não executam `git init`, não criam branches de integração nem fazem merge. |

## Nota adicional (CSP)

CSP estrita (`default-src 'self'`) em `/`; `/api/documentation*` e `/vendor/l5-swagger/` têm CSP própria que permite o Swagger UI (`'unsafe-inline'` em script/style, `img-src 'self' data:`), e esse `location` só existe com `L5_SWAGGER_ENABLED=true`.

## Versões

Pesquisa de 2026-10-08. Versões do backend confirmadas no packagist/`composer show` na F1-03; Node conforme decisão do Claudão (D11).

| Componente | Registro atual | Confirmação |
|---|---|---|
| Laravel (D1) | **Laravel 13.35.0** (`laravel/framework`; skeleton `laravel/laravel` v13.11.0), confirmado no packagist em 2026-10-08. Exige PHP ^8.3. | **confirmado (F1-03)** |
| PHP (D1) | **8.4.26** (`php:8.4.26-fpm-alpine`). 8.4 e não 8.3 porque o Pest exige ^8.4; o Laravel 13 aceita 8.3–8.5. | confirmado (F1-03) |
| Composer | 2.10.3 (imagem `composer:2.10.3`, copiado para a imagem do app) | confirmado |
| Sanctum | 4.3.3 (suporta Laravel 13) | confirmado |
| Horizon | 5.50.0 (suporta Laravel 13) | confirmado |
| Pest | **4.7.8** + `pest-plugin-laravel` 4.1.0 (PHPUnit 12.5.33), padrão do skeleton Laravel 13. Existe Pest 5.3.1 (PHP ^8.4, PHPUnit 13), **não adotado**: o skeleton fixa `^4.7`; avaliar upgrade quando o ecossistema estabilizar. | confirmado; compatível |
| Larastan | 3.13.0 (suporta Laravel 13) | confirmado (nível 5 configurado na F1-19) |
| l5-swagger | 11.1.0 (suporta Laravel 13). Instalado mas com auto-discovery **desativado** (`extra.laravel.dont-discover`) até a F1-07 configurar OpenAPI 3.0 e `L5_SWAGGER_ENABLED`. | confirmado; configurar em F1-07 |
| Pint | 1.32.1 (dev, do skeleton) | confirmado |
| phpredis | 6.2.0 (pecl) | confirmado |
| nginx | `nginx:1.30.5-alpine` (stable) | confirmado |
| PostgreSQL 16 + pgvector | `pgvector/pgvector:0.8.1-pg16` (F1-02) | fixado |
| Redis 7 | `redis:7.4.6-alpine` (F1-02) | fixado |
| Python 3.12, React 18 | conforme spec | tags exatas a fixar em F1-04/F1-21 |

## Pendências de decisão registradas

- Q7: o usuário vai criar o repositório no GitHub (remoto em configuração). O workflow GitHub Actions fica inativo e será ativado quando o remoto existir; até lá o gate é o `make ci` local.
- Usuário de aplicação do banco sem `UPDATE/DELETE` em `audit_logs` (F.1): se inviável na Fase 1, documentar aqui na F1-08.

## Notas de implementação (F1-03)

- Imagem do app: PHP-FPM como `www-data` (não-root), porta 9000, `pm.ping`/`pm.status` ativos; Horizon no serviço `worker` usa a mesma imagem. Dependências de dev (Pest, Pint, Larastan) ficam na imagem para o gate local (`make ci`); uma imagem de produção sem dev deps é evolução.
- O container não tem arquivo `.env`: a configuração vem das variáveis do compose. `make init` grava `APP_KEY` no `.env` do host.
- Redis: a senha vai para `/tmp/redis.conf` (umask 077) gerado a partir da env, fora da linha de comando do processo.
- `Access-Control-Allow-Origin: *` vem do middleware CORS padrão do Laravel; a política de CORS será definida na F1-06 (mesma origem via nginx).
