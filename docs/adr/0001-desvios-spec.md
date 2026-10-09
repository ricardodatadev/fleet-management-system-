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
| PHP (D1) | **8.4.26** (`php:8.4.26-fpm-alpine`). 8.4 escolhido por ser a série estável atual suportada pelo Laravel 13 (aceita 8.3–8.5), com suporte mais longo que a 8.3; permite migrar ao Pest 5 (exige PHP ^8.4) no futuro. O Pest 4.7 instalado aceita PHP 8.3. | confirmado (F1-03) |
| Composer | 2.10.3 (imagem `composer:2.10.3`, copiado para a imagem do app) | confirmado |
| Sanctum | 4.3.3 (suporta Laravel 13) | confirmado |
| Horizon | 5.50.0 (suporta Laravel 13) | confirmado |
| Pest | **4.7.8** + `pest-plugin-laravel` 4.1.0 (PHPUnit 12.5.33), padrão do skeleton Laravel 13. Existe Pest 5.3.1 (PHP ^8.4, PHPUnit 13), **não adotado**: o skeleton fixa `^4.7`; avaliar upgrade quando o ecossistema estabilizar. | confirmado; compatível |
| Larastan | 3.13.0 (suporta Laravel 13) | confirmado (nível 5 configurado na F1-19) |
| l5-swagger | 11.1.0 (suporta Laravel 13). Instalado mas com auto-discovery **desativado** (`extra.laravel.dont-discover`) até a F1-07 configurar OpenAPI 3.0 e `L5_SWAGGER_ENABLED`. | confirmado; configurar em F1-07 |
| Pint | 1.32.1 (dev, do skeleton) | confirmado |
| phpredis | 6.2.0 (pecl) | confirmado |
| nginx | `nginx:1.30.5-alpine` (stable) | confirmado |
| Node (D11) | **24 LTS** (decisão do Claudão; a 26 entra em LTS em 2026-10-20; revisar em fase futura). Imagem `node:24.21.0-alpine`. | confirmado (F1-05) |
| Node deps (F1-05) | Fastify 5.12.5, Socket.io 4.8.4, ioredis 6.0.0; dev: TypeScript 7.0.2, Vitest 5.0.3, socket.io-client 4.8.4, @types/node 24.19.1 (versões exatas + `package-lock.json`) | confirmado (npm) |
| Python (F1-04) | `python:3.12.15-slim`; FastAPI 0.143.0, Uvicorn 0.54.0 (dependências transitivas pinadas em `requirements.txt`); pytest 9.1.1, httpx 0.28.1 em `requirements-dev.txt` | confirmado (PyPI) |
| PostgreSQL 16 + pgvector | `pgvector/pgvector:0.8.1-pg16` (F1-02) | fixado |
| Redis 7 | `redis:7.4.6-alpine` (F1-02) | fixado |
| Python 3.12, React 18 | conforme spec | tags exatas a fixar em F1-04/F1-21 |

## Pendências de decisão registradas

- Q7: o usuário vai criar o repositório no GitHub (remoto em configuração). O workflow GitHub Actions fica inativo e será ativado quando o remoto existir; até lá o gate é o `make ci` local.
- Usuário de aplicação do banco sem `UPDATE/DELETE` em `audit_logs` (F.1): se inviável na Fase 1, documentar aqui na F1-08.

## Notas de implementação (F1-03)

- Imagem do app: PHP-FPM como `www-data` (não-root), porta 9000, `pm.ping`/`pm.status` ativos; Horizon no serviço `worker` usa a mesma imagem. Dependências de dev (Pest, Pint, Larastan) ficam na imagem para o gate local (`make ci`); a imagem `app` atual inclui composer + dev deps (adequada a dev/CI). **Pendência antes de qualquer deploy:** imagem de produção enxuta (target `prod`, `composer install --no-dev`, sem composer).
- O container não tem arquivo `.env`: a configuração vem das variáveis do compose. `make init` grava `APP_KEY` no `.env` do host.
- Redis: a senha vai para `/tmp/redis.conf` (umask 077) gerado a partir da env, fora da linha de comando do processo.
- `Access-Control-Allow-Origin: *` vem do middleware CORS padrão do Laravel; a política de CORS será definida na F1-06 (mesma origem via nginx).

## Notas de implementação (F1-06)

- **Envelope:** `ApiResponse` é a única fonte; chaves `status, message, errors, data` (+ `meta` só em listas). Tratamento de exceções em `bootstrap/app.php` para 401/403/404/405/409/422/429/500; 500 nunca vaza trace/SQL/paths (com `APP_DEBUG=true` acrescenta um bloco `debug`, só em dev). `X-Request-Id` aceita somente UUID (compatível com `audit_logs.request_id uuid`); valores inválidos são substituídos.
- **`GET /api/v1/meta/enums`:** criado como esqueleto **público e vazio** (`data.enums = {}`) porque a autenticação só existe na F1-09/10. `TODO(F1-10)`: proteger com `auth:sanctum`; o teste de varredura de rotas da F1-10 (allowlist só `health` e `auth/login`) falha se ela continuar sem permissão.
- **Health fora do throttle:** o limiter `api` (120/min) usa o cache (Redis); com o Redis fora o `/health` precisa responder 503, não 500.
- **CORS:** `config/cors.php` sem `*`, só `api/*`, sem credentials, origens por `CORS_ALLOWED_ORIGINS` (default vazio = mesma origem via nginx).
- **Testes em PostgreSQL isolado:** a spec depende de recursos exclusivos do PG (índices únicos parciais, CHECK, triggers no `audit_logs`, `pg_advisory_xact_lock`, jsonb, inet, `COALESCE` em unique), então a suíte roda em PostgreSQL, banco `sigof_test` (com `vector`), nunca em SQLite. O container traz `DB_CONNECTION`/`CACHE_STORE` de dev e o `$_SERVER` vence o `$_ENV` do PHPUnit; por isso `phpunit.xml` força os valores de teste também em `<server>`. Guarda em `Tests\TestCase::createApplication`: a suíte aborta se o driver não for `pgsql` ou o banco não terminar em `_test`. O banco é criado por `docker/postgres/create-test-db.sh` (init em volume novo; `make test-db` em volume existente). O health "up" usa o Redis real do compose.
- **Dev (override):** bind mount de `./backend` em `app`/`worker` com o `vendor` da imagem em volume nomeado `app_vendor`, rodando com `HOST_UID/HOST_GID` (default 1000) e `opcache.validate_timestamps=1` via `docker/laravel/php-dev.ini`. Após mudar `composer.json/lock`: rebuild + `docker volume rm sigof_app_vendor`. Base/prod inalterada.

## Notas de implementação (F1-07)

- **OpenAPI 3.0.0** gerada por `openapi:generate` (swagger-php 6.12.0 via l5-swagger 11.1.0, atributos PHP) e normalizada (JSON pretty, estável p/ diff) em `docs/api/openapi.json`. Servidor `/api/v1`, `bearerAuth` (http bearer), schemas `Envelope, Pagination, ErrorEnvelope, ValidationErrorEnvelope` e respostas reutilizáveis (`Unauthenticated, Forbidden, NotFound, Conflict, ValidationError, TooManyRequests, ServerError`). O arquivo é copiado para a imagem do app (`/var/www/docs/api`) e, em dev, `./docs` é montado no override para o gerador gravar no repositório.
- **Swagger UI** (`/api/documentation`, `/api/documentation/spec[/asset/*]`) só é registrado com `L5_SWAGGER_ENABLED=true` (config condicional → com `false` as rotas não existem, 404 no envelope). A UI serve o JSON versionado. No nginx o `location ^~ /api/documentation` com CSP própria (`script-src/style-src 'self' 'unsafe-inline'`, `img-src 'self' data:`) só é incluído pelo entrypoint `40-swagger.sh` quando a variável é true; `/` e o restante da API mantêm `default-src 'self'`.
- **Lint Redocly 2.60.0** (recommended): 0 erros. Avisos aceitos: `info-license-strict` (licença proprietária, sem URL); `operation-4xx-response` em `/health` (público, sem entrada que gere 4xx; responde 200/503); `no-unused-components` para as respostas reutilizáveis e `bearerAuth` — ficam disponíveis para os endpoints das próximas tarefas (auth, cadastros) e desaparecem à medida que forem referenciados.
- **Banco de teste só em dev:** `create-test-db.sh` agora é montado apenas no `docker-compose.override.yml` (o compose base/produção não cria `sigof_test`).

## Notas de implementação (F1-08 — audit trail)

- **Tabela e imutabilidade:** `audit_logs` (C.8) com `uuid`/`event_at` gerados pela aplicação; triggers `BEFORE UPDATE OR DELETE` (por linha) e `BEFORE TRUNCATE` (por statement) levantam exceção (`42501`, "audit_logs é append-only"). O model `AuditLog` (e seu builder) lança `AuditImmutableException` em update/delete/save de existente. Observação: um `UPDATE/DELETE` que não casa nenhuma linha não dispara o trigger por linha (não há o que proteger).
- **Hash encadeado:** `hash = sha256(prev_hash || canonical_json)`; `canonical_json` em `App\Support\Audit\CanonicalJson/AuditHasher` (15 campos, chaves ordenadas, `null` explícito, `event_at` ISO-8601 UTC com µs e `Z`, floats sem notação científica; campos JSON passam por round-trip e `[]` raiz é `{}`, para o hash bater com o que o `jsonb` devolve). Escrita serializada por `pg_advisory_xact_lock` dentro da transação; primeiro `prev_hash` = 64 zeros. `php artisan audit:verify` retorna exit ≠ 0 e o primeiro `id` divergente (conteúdo adulterado ou registro removido/reordenado).
- **Convenção de transação:** os observers do `Auditable` disparam após a escrita do model; para o log ser atômico com a mudança, as mutações devem rodar dentro de `DB::transaction()` (controllers/services de escrita). O `AuditService::record` abre transação própria se não houver.
- **Origem:** `source = http` quando a requisição passou pelo middleware `RequestId`; `queue` durante jobs (`Queue::before/after`); senão `console`.
- **Revoke de UPDATE/DELETE para o usuário de aplicação — pendência (inviável hoje):** a aplicação usa o mesmo usuário (`POSTGRES_USER`) que é superusuário e dono das tabelas; um `REVOKE` não o afeta (donos/superusuários podem desabilitar triggers). **Proposta:** dois papéis — `sigof_owner` (migrations/DDL, usado só por `make migrate`/deploy) e `sigof_app` (runtime: `SELECT, INSERT` em `audit_logs` e `USAGE` na sequence, sem `UPDATE/DELETE/TRUNCATE`, sem ownership), com `DB_USERNAME` de runtime ≠ usuário de migração. Fazer antes do deploy em produção (junto com a imagem `prod` enxuta). Enquanto isso a imutabilidade é garantida por triggers + `audit:verify` (tamper-evidence), não por privilégios.
- **Testes:** Pest em `sigof_test`, incluindo concorrência real com 2 processos PHP (`tests/Support/audit_writer.php`, 25 eventos cada, conexões e transações próprias): sem o advisory lock a cadeia bifurca (49≠50 `prev_hash` distintos); com o lock é válida.

## Notas de implementação (F1-09 — usuários e autenticação)

- **Somente Bearer (D2):** `config/sanctum.php` com `guard = []` e `stateful = []`. O Sanctum não tenta sessão/cookie, só personal access tokens. Validade = `SANCTUM_EXPIRATION` minutos (padrão 720), aplicada pelo `expiration` do Sanctum e gravada em `personal_access_tokens.expires_at` (`NOT NULL`).
- **Throttle de login por tentativa:** limiter `login` = 5/min por (e-mail normalizado + IP) **e** 20/min por IP. Conta **toda** tentativa, inclusive as bem-sucedidas (comportamento do middleware `throttle`). Requisições bloqueadas (429) não chegam ao controller e não geram audit.
- **Respostas do login:** credenciais inválidas, e-mail inexistente e usuário soft-deleted → mesmo 422 genérico (hash conferido também sem usuário, para o tempo não denunciar o e-mail). Usuário inativo → 403 **somente** com a senha correta. Token já emitido de usuário inativo ou soft-deleted → 401 (`Sanctum::authenticateAccessTokensUsing`).
- **Auditoria de auth:** `login_succeeded` (actor = auditable = usuário; o ator é definido com `Auth::setUser` antes do `record`, porque no login ainda não há usuário autenticado), `login_failed` (actor/auditable nulos, `metadata {email, reason}` com `reason ∈ invalid_credentials | inactive`), `logout` e `password_changed` (actor = auditable = usuário; `metadata.revoked_tokens`). Login (token + `last_login_at` + audit) e troca de senha (senha + revogação dos outros tokens + audit) rodam em `DB::transaction`. `last_login_at` fica no `$auditExclude` do `User`.
- **Dados:** e-mail gravado e comparado em minúsculas (UQ parcial `WHERE deleted_at IS NULL`); `CHECK (role = 'admin' OR branch_id IS NOT NULL)`; `branches` só com estrutura (C.2), CRUD/Auditable na F1-12.
- **`config/rbac.php`** criado na F1-09 só com os dados da matriz E (lista explícita por perfil; `auth.*` implícito; restore/with_trashed contidos em `*.manage`), para alimentar `permissions` de `/auth/me`. Gates/Policies/`can:` e o teste da matriz ficam na F1-10. `employee` em `/auth/me` é `null` até a F1-14.
- **Política de senha:** `Password::defaults()` = mín. 10, maiúscula + minúscula + número (reutilizada pelo CRUD de usuários na F1-11).

## Notas de implementação (F1-10 — RBAC)

- **Gates:** um Gate por permissão de `config/rbac.php` (`App\Support\Rbac\Rbac::register`), concedido se a permissão está na lista do perfil, mais o Gate implícito **`auth.session`** (qualquer autenticado), que representa o `auth.*` da matriz E.
- **Autorização declarada na rota:** toda rota de `api/v1` fora da allowlist (`health`, `auth/login`) declara `auth:sanctum` **e** `can:<permissão>` (permissão da matriz ou `auth.session`). Um teste de varredura falha se faltar qualquer um dos dois ou se o `can:` usar ability fora da matriz. `meta/enums` passou a exigir token (perfil "auth" da D.2).
- **Precedência 403 × 404:** o alias `can` aponta para `App\Http\Middleware\AuthorizePermission`, que roda **depois da autenticação e antes do `SubstituteBindings`** (`prependToPriorityList`). Sem a permissão do recurso a resposta é 403 em todas as rotas dele, inclusive `/{id}` de outra filial. Com a permissão e o registro fora do escopo, o binding (com o escopo de filial) devolve 404. Por rodar antes do binding, o `can:` só aceita ability sem argumento ou com nome de classe. Abilities por instância (`view`/`update` de um registro) são verificadas no controller, depois do binding; `can:` com parâmetro de rota lança `LogicException`.
- **Escopo de filial:** trait `App\Models\Concerns\BranchScoped` (global scope `BranchScope`). Usuário autenticado não-admin → `branch_id = user.branch_id`. Admin (com ou sem filial) e contextos sem usuário (console, fila) não são filtrados. Validação `exists` usa o query builder e não é afetada. Opção por model `protected bool $branchScopeIncludesNull = true` (não-admin vê também registros sem filial; usada pelo CostCenter na F1-12). `branches` não tem escopo (lookup visível a quem tem `branches.view`).
- **Policies:** `ResourcePolicy` base (`viewAny`/`view` → `{r}.view`; `create`/`update`/`delete`/`restore`/`viewTrashed` → `{r}.manage`; `forceDelete` sempre negado; por instância aplica o escopo de filial quando o model usa `BranchScoped`). Concretas só para os models existentes: `UserPolicy`, `BranchPolicy`, `AuditLogPolicy` (só leitura com `audit.view`). Cada cadastro F1-11..16 entrega a própria policy, o `can:` nas rotas e as linhas do dataset por rota (`tests/Feature/Rbac/PermissionMatrixTest.php`).
- **Teste da matriz em duas camadas:** (a) a tabela E transcrita literalmente no teste, perfil × cada linha (incl. `auth.*` e `*.restore/with_trashed`) via Gate, e comparada com `config/rbac.php`; (b) perfil × rota × HTTP esperado para as rotas existentes, com checagem de que o dataset cobre todas as rotas protegidas.
