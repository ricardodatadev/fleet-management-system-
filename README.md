# SIGOF-M — Sistema Integrado de Gestão e Otimização de Frota e Manutenção

Monorepo da Fase 1 (fundação + cadastros). Fonte de verdade da fase: especificação `fase-1-especificacao` v1.1.

> **Estado atual (F1-04):** compose com nginx, app (Laravel 13 / PHP 8.4), worker (Horizon), db, redis, python-ai (interno) e node-realtime (Socket.io recusa conexões até a F1-18). O frontend chega nas próximas tarefas; alvos `make` ainda não implementados falham com mensagem clara.

## Arquitetura

Monólito modular Laravel + 2 serviços, tudo em Docker Compose (projeto `sigof`).

| Serviço | Papel | Pasta |
|---|---|---|
| nginx | Único ponto público; serve a SPA, `/api/` → PHP-FPM, `/socket.io/` → node | `docker/nginx` |
| app | Laravel (PHP-FPM) | `backend`, `docker/laravel` |
| worker | Laravel Horizon | `backend` |
| db | PostgreSQL 16 + pgvector | `docker/postgres` |
| redis | Redis 7 (filas, cache, sessão, pub/sub) | — |
| python | FastAPI (`/health`; interno) | `services/python-ai` |
| node | Fastify + Socket.io | `services/node-realtime` |
| frontend | Vite HMR (somente no override de dev) | `frontend` |

## Quickstart (quando F1-02..F1-17 estiverem prontas)

```bash
cp .env.example .env      # preencha TODOS os valores "change-me"
make up                   # sobe os containers
make init                 # gera APP_KEY no .env (se vazia) + migrate
make seed                 # admin + parâmetros (+ demo em local/testing)
bash scripts/smoke.sh     # verificação ponta a ponta (F1-20)
```

Acesse `http://localhost:${WEB_HTTP_PORT}/` (padrão 80; use ex. 8080 se a porta 80 estiver ocupada ou sem privilégio). Health do nginx: `/healthz`. Documentação da API: `/api/documentation` apenas com `L5_SWAGGER_ENABLED=true`.

Alvos do Makefile: `up, down, init, migrate, seed, test, lint, ci` (`up`/`down`/`init`/`migrate`/`test` reais; `test-db`; `seed`, `lint`, `ci` são stubs até F1-17/F1-19).

## Desenvolvimento

O `docker-compose.override.yml` (dev) monta `./backend` nos containers `app`/`worker` (recarga sem rebuild) e roda com o seu uid: `export HOST_UID=$(id -u) HOST_GID=$(id -g)` (default 1000). Se mudar `composer.json/lock`: `docker compose build app && docker compose down && docker volume rm sigof_app_vendor`. Testes: `make test` (Pest no container `app`) rodam em **PostgreSQL**, no banco `sigof_test` (nunca no de dev): `Tests\TestCase` aborta se o driver não for `pgsql` ou o banco não terminar em `_test`. O banco de testes é criado pelo init do postgres em volumes novos; em volumes antigos `make test-db` (idempotente, também executado por `make test`) cria o banco e a extensão `vector`. CORS extra só via `CORS_ALLOWED_ORIGINS`.

## Portas

- `docker-compose.yml` (base/produção): publica **somente o nginx** em `${WEB_HTTP_PORT:-80}`.
- `docker-compose.override.yml` (dev, carregado automaticamente): db, redis, python e node em `127.0.0.1` apenas.
- TLS: terminado na borda em produção (bloco 443 comentado no nginx).

## Segredos

Nenhum segredo no repositório. Tudo vem de `${VAR}` do `.env` (ignorado pelo git); `.env.example` usa placeholders `change-me`. Não use o compose com senhas padrão.

## Git

Repositório, `main`, `develop` e merges são do Escrivão. Branches `feat/f1-XX-slug`, commits convencionais.

## Desvios em relação à especificação original

Resumo; detalhes em [`docs/adr/0001-desvios-spec.md`](docs/adr/0001-desvios-spec.md): Laravel última estável (D1), Sanctum Bearer e não OAuth2 (D2), monólito modular + 2 serviços, sem K8s (D3), só nginx publicado (D4), sem senhas hardcoded (D5), sem `version:` no compose (D6), código dos serviços em `services/` (D7), nomes de serviço sem `container_name` (D8), envelope próprio em vez de JSON:API (D9), somente Redis (D10), Node 24 LTS (D11), sem Horizon exposto (D12), papéis Almoxarife/Financeiro adiados (D13), RN-002 placeholder (D14), Bearer em localStorage com CSP estrita (D15), git sob o Escrivão (D16).

## Troubleshooting

A preencher conforme as tarefas F1-02..F1-20 forem entregues.
