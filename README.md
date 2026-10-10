# GOF — Gestão Operacional de Frotas

Monorepo da Fase 1 (fundação + cadastros). Fonte de verdade da fase: especificação `fase-1-especificacao` (v1.14). Evidências do gate: [`docs/phase-1-evidence.md`](docs/phase-1-evidence.md).

> **Estado (Fase 1 concluída na F1-20):** API Laravel com autenticação por username, RBAC, auditoria encadeada, cadastros (filiais, centros de custo, famílias, equipamentos, colaboradores, usuários) e parâmetros por escopo; SPA React servida pelo nginx; tempo real com handshake autenticado; serviço python interno. Fora de escopo nesta fase: OS, preventiva, estoque, pneus, checklist, telemetria, BI, RAG.

## Arquitetura

Monólito modular Laravel + 2 serviços, tudo em Docker Compose (projeto = `APP_SLUG` do `.env`).

| Serviço | Papel | Pasta |
|---|---|---|
| nginx | Único ponto público; serve a SPA, `/api/` → PHP-FPM, `/socket.io/` → node | `docker/nginx` |
| app | Laravel (PHP-FPM) | `backend`, `docker/laravel` |
| worker | Laravel Horizon (filas) | `backend` |
| scheduler | Agendador do Laravel (`schedule:work`): prune diário de tokens e batimento do healthcheck | `backend` |
| db | PostgreSQL 16 + pgvector | `docker/postgres` |
| redis | Redis 7 (filas, cache, sessão, pub/sub) | — |
| python | FastAPI (`/health`; interno) | `services/python-ai` |
| node | Fastify + Socket.io (handshake: token Bearer validado no `/auth/me`) | `services/node-realtime` |
| frontend | Vite HMR (somente no override de dev) | `frontend` |

## Quickstart (subir do zero)

Requisitos no host: Docker com Compose v2, `make`, `git`, `curl` e `python3` (este último só para o smoke). Nada de PHP/Node/Python no host.

```bash
cp .env.example .env      # preencha TODOS os "change-me" (senhas do banco/redis/seed) e, se precisar, WEB_HTTP_PORT
make up                   # build + sobe os containers (espera ficarem healthy)
make init                 # gera APP_KEY no .env (se vazia), recria e roda as migrations
make seed                 # parâmetros globais + admin (+ dados demo em local/testing)
make smoke                # = bash scripts/smoke.sh: verificação ponta a ponta num clone limpo isolado
```

Acesse `http://localhost:${WEB_HTTP_PORT}/` (padrão 80; use ex. 8080 se a porta 80 estiver ocupada ou sem privilégio) e entre com o **username** do admin (`SEED_ADMIN_USERNAME`) e a senha `SEED_ADMIN_PASSWORD`. Health do nginx: `/healthz`; da API: `/api/v1/health`. Documentação da API: `/api/documentation` apenas com `L5_SWAGGER_ENABLED=true`.

**Usuários de demonstração** (só com `APP_ENV=local`/`testing`, criados pelo `make seed`), todos com a senha `SEED_DEMO_PASSWORD`: `demo.admin` (administrador, sem filial), `demo.lider` (líder), `demo.mecanico` (mecânico) e `demo.operador` (operador), estes na filial `DEMO-01`.

### Smoke E2E

`make smoke` (ou `bash scripts/smoke.sh`) não usa a sua stack: clona o commit atual (HEAD; mudanças não commitadas ficam de fora) num diretório temporário, gera um `.env` de produção com segredos aleatórios e sobe só o `docker-compose.yml` num projeto do compose próprio, com o nginx numa porta livre. Verifica os 8 serviços healthy, migrate/seed, batimento do scheduler, pgvector, que só o nginx publica porta, a SPA servida pelo nginx (o `index.html` do build com o `<title>` do `APP_NAME` e CSP, também numa rota profunda), login por username, criação de família e equipamento pela API, os eventos no audit log, `audit:verify`, o handshake Socket.io pelo nginx, o `/health` do python e do node, erro sem vazamento de stack e containers não-root. Imprime uma tabela PASS por verificação, sai 0 só se tudo passar e limpa containers, volumes, imagens e o clone no fim (`SMOKE_KEEP=1` mantém a stack para inspeção). Leva alguns minutos (build das imagens).

### Alvos do Makefile

| Alvo | O que faz |
|---|---|
| `up` / `down` | sobe (build + espera healthy) / derruba a stack |
| `init` | gera `APP_KEY` se faltar, sobe e migra |
| `migrate` / `seed` | `migrate --force` / `db:seed --force` (idempotente) |
| `test-db` | cria o banco `<POSTGRES_DB>_test` (idempotente) |
| `test` | Pest com cobertura (pcov, imagem de dev), mínimo de 80% em `app/` |
| `test-python` / `test-node` | pytest / Vitest na imagem `test` de cada serviço |
| `openapi` / `openapi-lint` | gera `docs/api/openapi.json` / lint com Redocly |
| `brand` | guarda do nome do sistema (`scripts/check-brand.sh`) |
| `lint` | guarda de marca + teste da guarda + teste do compose + Pint + Larastan (nível 5, sem baseline) |
| `ci` | **gate local:** `lint` + `test` + `test-python` + `test-node` + `openapi-lint` |
| `smoke` | smoke E2E num clone limpo isolado |
| `vendor-reset` | após mudar `composer.json/lock`: rebuild e troca do volume do vendor |

O frontend tem o próprio gate (`npm run ci` em `frontend/`, num container `node:24.21.0-alpine`). O workflow `.github/workflows/ci.yml` faz o mesmo que o `make ci` numa máquina limpa, mas está inativo (só disparo manual) até haver remoto.

## Desenvolvimento

O `docker-compose.override.yml` (dev) monta `./backend` nos containers `app`/`worker` (recarga sem rebuild) e roda com o seu uid: `export HOST_UID=$(id -u) HOST_GID=$(id -g)` (default 1000). Se mudar `composer.json/lock`: `make vendor-reset` (rebuild do `app` + `docker volume rm <projeto>_app_vendor`). Testes: `make test` (Pest no container `app`) rodam em **PostgreSQL**, no banco `<POSTGRES_DB>_test` (nunca no de dev): `Tests\TestCase` aborta se o driver não for `pgsql` ou o banco não terminar em `_test`. O banco de testes é criado pelo init do postgres em volumes novos; em volumes antigos `make test-db` (idempotente, também executado por `make test`) cria o banco e a extensão `vector`. CORS extra só via `CORS_ALLOWED_ORIGINS`.

## OpenAPI

Contrato da API: `docs/api/openapi.json` (OpenAPI 3.0, versionado), gerado dos atributos PHP (swagger-php): `make openapi` (= `composer openapi` no container). Lint: `make openapi-lint` (Redocly em container node efêmero; 0 erros, avisos justificados no ADR). Os testes Pest falham se uma rota `api/v1` não estiver documentada ou se o JSON versionado estiver desatualizado. Swagger UI: `L5_SWAGGER_ENABLED=true` no `.env` e recriar `nginx`/`app` → `/api/documentation` (com `false` a rota não existe e responde 404). A CSP do Swagger UI é própria desse location; `/` mantém `default-src 'self'`.

## Tempo real (Socket.io)

O cliente conecta em `/socket.io/` na mesma origem (o nginx faz o upgrade para WebSocket) enviando o token da API em `auth: { token }`. O node valida o token com `GET ${LARAVEL_INTERNAL_URL}/api/v1/auth/me` (timeout de 1,8 s, para a recusa chegar ao cliente em ≤ 2 s): se ok, o socket entra nas salas `user:{id}`, `role:{perfil}` e `branch:{id}` (só quando o usuário tem filial; admin sem filial não entra em sala de filial) e recebe `session:ready` com o usuário. Sem token, token inválido ou revogado, Laravel fora ou lento → `connect_error` com a mensagem `unauthorized` (fail-closed). O token não é logado. Verificação manual: `services/node-realtime/scripts/handshake-check.mjs` (instruções no próprio arquivo).

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

- **Porta 80 ocupada ou sem privilégio:** defina `WEB_HTTP_PORT=8080` (ou outra) no `.env` e rode `make up`.
- **`db:seed` falha com "Defina SEED_..." ou "senha inválida":** preencha `SEED_ADMIN_USERNAME`/`SEED_ADMIN_EMAIL`/`SEED_ADMIN_PASSWORD` (e `SEED_DEMO_PASSWORD` em local) com uma senha que passe na política; `change-me` é recusada de propósito.
- **Login recusado ao usar o e-mail:** o login é só por **username**; o e-mail serve apenas para "esqueci a senha".
- **Erro 500 "No application encryption key":** falta `APP_KEY=base64:...` no `.env`; rode `make init` (gera a chave e recria os containers).
- **`worker`/`scheduler` tentando baixar `<slug>/app:local`:** a imagem é construída pelo serviço `app` (`pull_policy: never`); rode `make up` (que faz o build) em vez de subir só esses serviços.
- **`scheduler` unhealthy:** o healthcheck exige batimento com menos de 150 s em `storage/framework/schedule-heartbeat`; veja `docker compose logs scheduler` (normalmente banco ou redis fora).
- **Testes reclamam do banco `_test`:** `make test-db` cria o banco de testes em volumes antigos; os testes nunca rodam no banco de dev.
- **Depois de mudar `composer.json`/`composer.lock`:** `make vendor-reset`.
- **Socket.io recusa a conexão (`unauthorized`):** o token precisa ser válido e ainda não expirado (12 h); o node valida no `/api/v1/auth/me` com timeout de 1,8 s.
- **E-mail de redefinição não chega:** em dev, veja o Mailpit (`http://127.0.0.1:8025`); fora do override o padrão é `MAIL_MAILER=log` (o e-mail vai para o log do `worker`).
