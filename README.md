# GOF — Gestão Operacional de Frotas

Monorepo da Fase 1 (fundação + cadastros). Fonte de verdade da fase: especificação `fase-1-especificacao` v1.1.

> **Estado atual (F1-04):** compose com nginx, app (Laravel 13 / PHP 8.4), worker (Horizon), db, redis, python-ai (interno) e node-realtime (Socket.io com handshake autenticado pelo token da API). O frontend chega nas próximas tarefas; alvos `make` ainda não implementados falham com mensagem clara.

## Arquitetura

Monólito modular Laravel + 2 serviços, tudo em Docker Compose (projeto = `APP_SLUG` do `.env`).

| Serviço | Papel | Pasta |
|---|---|---|
| nginx | Único ponto público; serve a SPA, `/api/` → PHP-FPM, `/socket.io/` → node | `docker/nginx` |
| app | Laravel (PHP-FPM) | `backend`, `docker/laravel` |
| worker | Laravel Horizon | `backend` |
| db | PostgreSQL 16 + pgvector | `docker/postgres` |
| redis | Redis 7 (filas, cache, sessão, pub/sub) | — |
| python | FastAPI (`/health`; interno) | `services/python-ai` |
| node | Fastify + Socket.io (handshake: token Bearer validado no `/auth/me`) | `services/node-realtime` |
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

Alvos do Makefile: `up, down, init, migrate, seed, test, test-db, openapi, openapi-lint, brand, lint, ci, vendor-reset`. `lint` = guarda de marca + teste da guarda + Pint (Larastan chega na F1-19); `ci` = `lint` + `test`; `seed` é stub até a F1-17.

## Desenvolvimento

O `docker-compose.override.yml` (dev) monta `./backend` nos containers `app`/`worker` (recarga sem rebuild) e roda com o seu uid: `export HOST_UID=$(id -u) HOST_GID=$(id -g)` (default 1000). Se mudar `composer.json/lock`: `make vendor-reset` (rebuild do `app` + `docker volume rm <projeto>_app_vendor`). Testes: `make test` (Pest no container `app`) rodam em **PostgreSQL**, no banco `<POSTGRES_DB>_test` (nunca no de dev): `Tests\TestCase` aborta se o driver não for `pgsql` ou o banco não terminar em `_test`. O banco de testes é criado pelo init do postgres em volumes novos; em volumes antigos `make test-db` (idempotente, também executado por `make test`) cria o banco e a extensão `vector`. CORS extra só via `CORS_ALLOWED_ORIGINS`.

## OpenAPI

Contrato da API: `docs/api/openapi.json` (OpenAPI 3.0, versionado), gerado dos atributos PHP (swagger-php): `make openapi` (= `composer openapi` no container). Lint: `make openapi-lint` (Redocly em container node efêmero; 0 erros, avisos justificados no ADR). Os testes Pest falham se uma rota `api/v1` não estiver documentada ou se o JSON versionado estiver desatualizado. Swagger UI: `L5_SWAGGER_ENABLED=true` no `.env` e recriar `nginx`/`app` → `/api/documentation` (com `false` a rota não existe e responde 404). A CSP do Swagger UI é própria desse location; `/` mantém `default-src 'self'`.

## Tempo real (Socket.io)

O cliente conecta em `/socket.io/` na mesma origem (o nginx faz o upgrade para WebSocket) enviando o token da API em `auth: { token }`. O node valida o token com `GET ${LARAVEL_INTERNAL_URL}/api/v1/auth/me` (timeout de 2 s): se ok, o socket entra nas salas `user:{id}`, `role:{perfil}` e `branch:{id}` (só quando o usuário tem filial; admin sem filial não entra em sala de filial) e recebe `session:ready` com o usuário. Sem token, token inválido ou revogado, Laravel fora ou lento → `connect_error` com a mensagem `unauthorized` (fail-closed). O token não é logado. Verificação manual: `services/node-realtime/scripts/handshake-check.mjs` (instruções no próprio arquivo).

## Dados iniciais (seeders)

`make seed` (= `php artisan db:seed --force` no container) é idempotente: rodar de novo não duplica nem altera o que já existe.

- **Todos os ambientes:** os 4 parâmetros globais com o default do registry (um valor já alterado pelo admin é mantido) e o administrador inicial de `SEED_ADMIN_USERNAME`/`SEED_ADMIN_EMAIL`/`SEED_ADMIN_PASSWORD` (+ `SEED_ADMIN_NAME` opcional). Sem alguma delas, o seed falha com a lista do que falta; username pela regra de usuário e senha pela política. Se o username já existir, nada muda (nem a senha).
- **Só `local`/`testing`:** dados de demonstração com prefixo `DEMO` (2 filiais, 3 centros de custo, 5 famílias, 32 equipamentos, colaboradores) e 1 usuário por perfil (`demo.admin`, `demo.lider`, `demo.mecanico`, `demo.operador`) com a senha `SEED_DEMO_PASSWORD`. Em `production` o DemoSeeder não roda.
- Nenhuma senha fica no código: tudo vem do `.env` (o `.env.example` traz `change-me`, que a política recusa de propósito).

## Autenticação e e-mail

- **Login só por usuário (username) + senha** (`POST /api/v1/auth/login` com `{username, password, device_name}`); o username passa por trim + minúsculas no servidor. O e-mail serve apenas para a recuperação de senha.
- **Esqueci a senha:** `POST /auth/forgot-password` (sempre 200, sem revelar se o e-mail existe) envia, pela fila, um link `${APP_FRONTEND_URL}/redefinir-senha#token=…&email=…` (o token vai no fragmento, que não chega ao servidor); `POST /auth/reset-password` troca a senha e encerra todas as sessões.
- **E-mail em dev:** o `docker-compose.override.yml` sobe o Mailpit e aponta `app`/`worker` para ele; os e-mails aparecem em `http://127.0.0.1:${MAILPIT_UI_PORT:-8025}`. Fora do override o padrão é `MAIL_MAILER=log`.

## Nome do sistema

O nome vem de uma fonte única: `APP_NAME`, `APP_FULL_NAME` e `APP_SLUG` no `.env` (modelo no `.env.example`). O nome exibido (API/OpenAPI, Swagger, FastAPI, SPA) vem dos dois primeiros; todo identificador técnico (projeto do compose, imagens, rede, volumes, banco, prefixos de cache/Redis/sessão/Horizon, chave de sessão do front) deriva do slug. `make brand` (`scripts/check-brand.sh`) falha se o nome aparecer fora da allowlist (`scripts/brand-allowlist.txt`). Para trocar o nome, veja [`docs/renaming.md`](docs/renaming.md).

## Portas

- `docker-compose.yml` (base/produção): publica **somente o nginx** em `${WEB_HTTP_PORT:-80}`.
- `docker-compose.override.yml` (dev, carregado automaticamente): db, redis, python e node em `127.0.0.1` apenas.
- TLS: terminado na borda em produção (bloco 443 comentado no nginx).

## Segredos

Nenhum segredo no repositório. Tudo vem de `${VAR}` do `.env` (ignorado pelo git); `.env.example` usa placeholders `change-me`. Não use o compose com senhas padrão.

## Git

Repositório, `main`, `develop` e merges são do Escrivão. Branches `feat/f1-XX-slug`, commits convencionais.

## Desvios em relação à especificação original

Resumo; detalhes em [`docs/adr/0001-desvios-spec.md`](docs/adr/0001-desvios-spec.md): Laravel última estável (D1), Sanctum Bearer e não OAuth2 (D2), monólito modular + 2 serviços, sem K8s (D3), só nginx publicado (D4), sem senhas hardcoded (D5), sem `version:` no compose (D6), código dos serviços em `services/` (D7), nomes de serviço sem `container_name` (D8), envelope próprio em vez de JSON:API (D9), somente Redis (D10), Node 24 LTS (D11), sem Horizon exposto (D12), papéis Almoxarife/Financeiro adiados (D13), RN-002 placeholder (D14), Bearer em localStorage com CSP estrita (D15), git sob o Escrivão (D16), nome do sistema em fonte única com guarda (D17).

## Troubleshooting

A preencher conforme as tarefas F1-02..F1-20 forem entregues.
