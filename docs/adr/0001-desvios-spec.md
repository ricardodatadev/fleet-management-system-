# ADR-0001 — Desvios da especificação e decisões da Fase 1

- **Status:** aceito (spec Fase 1 v1.1, aprovada pelo Claudão; Fase 1 liberada pelo usuário)
- **Data:** 2026-10-08

## Contexto

A SRS v2.0.0 e a Especificação Técnica 01 v1.0.0 contêm itens que a Fase 1 intencionalmente não segue ao pé da letra. Este ADR registra cada desvio (D1–D17).

## Decisões

| # | Spec original | Decisão na Fase 1 |
|---|---|---|
| D1 | Laravel 11 / PHP 8.3 | Última versão estável do Laravel e o PHP mínimo/recomendado por ela. Se Horizon/Sanctum/l5-swagger/Pest não suportarem a versão, parar e acionar o Mr. Brain. |
| D2 | Sanctum "(OAuth2)" | Sanctum não é OAuth2. Fase 1 = Sanctum com Bearer personal access tokens (expiração 12 h). OIDC (RNF-005) fica como evolução; AES-256 em repouso e TLS 1.3 são responsabilidade da infra de produção (borda/LB, disco), fora do compose. |
| D3 | RNF-007 microsserviços/K8s | Monólito modular + 2 serviços (python, node). Somente Docker Compose; K8s fora do MVP. |
| D4 | Portas 5432/6379/8000/3000 no compose | Compose base publica só o nginx (`${WEB_HTTP_PORT:-80}`). Portas de dev (127.0.0.1) apenas em `docker-compose.override.yml`. |
| D5 | senha literal hardcoded no compose | Tudo via `${VAR}` do `.env` (sem default de senha no compose); `.env.example` com `change-me`. Redis com `requirepass`. |
| D6 | `version: '3.8'` | Removido. `name: ${APP_SLUG:-<slug>}` no topo: o projeto do compose é o slug da marca (D17), e rede, volumes e containers herdam esse prefixo. |
| D7 | `docker/python-ai`, `docker/node-realtime` | Código em `services/python-ai` e `services/node-realtime` (Dockerfile em cada serviço). `docker/` guarda só nginx, laravel, postgres. |
| D8 | Nomes `<slug>-*` + `container_name` | Serviços `nginx, app, worker, db, redis, python, node` (+ `frontend` só no override). Sem `container_name`: o compose nomeia containers, rede (`<slug>_internal`) e volumes (`<slug>_db_data`...) a partir do projeto `${APP_SLUG}`. Imagens `${APP_SLUG}/<svc>:local`. |
| D9 | "JSON:API" | Envelope próprio (`status, message, errors, data, meta`), não a spec JSON:API. |
| D10 | Redis / RabbitMQ | Somente Redis (filas, cache, sessão, pub/sub). RabbitMQ removido. |
| D11 | Node 20 | **Node 24 LTS** (decisão do Claudão em 2026-10-08) para `node` e build do frontend. A 26 só vira LTS em 2026-10-20; revisar em fase futura. Python 3.12, PostgreSQL 16, Redis 7, React 18 mantidos. |
| D12 | Horizon | `/horizon` não exposto pelo nginx na Fase 1; verificação via `horizon:status`. |
| D13 | Papéis Almoxarife/Financeiro (matriz §5 SRS) | Fora de RN-007; ficam para fase própria. Enum de perfis extensível. |
| D14 | RN-002 (matriz por Categoria de Serviço) | `workorder.block_close_without_labor` é placeholder (bool global/filial); a RN-002 real será modelada na fase de OS, substituindo a chave. RN-001 "Modo de Disparo" está adequada. |
| D15 | Token Bearer em `localStorage` | Aprovado na Fase 1 com CSP estrita. **Risco:** XSS pode roubar o token. Revisão obrigatória na fase do PWA/offline (avaliar cookie httpOnly/OIDC). |
| D16 | Git | Repositório e fluxo pertencem ao Escrivão. Senior/Íris não executam `git init`, não criam branches de integração nem fazem merge. |
| D17 | Nome SIGOF-M (Sistema Integrado de Gestão e Otimização de Frota e Manutenção) espalhado como literal | Rebrand para **GOF — Gestão Operacional de Frotas** (2026-10-09, F1-33a/F1-33b). Fonte única: `APP_NAME`, `APP_FULL_NAME` e `APP_SLUG` no `.env` (backend: `config/app.php` `name`/`full_name`/`slug`; front: `src/config/brand.ts`); identificadores técnicos derivam do slug. Guarda `scripts/check-brand.sh` (em `make lint`/`make ci` e no workflow) com allowlist em `scripts/brand-allowlist.txt`; o nome anterior só existe nesta linha. Detalhes abaixo e em `docs/renaming.md`. |

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
- **Testes em PostgreSQL isolado:** a spec depende de recursos exclusivos do PG (índices únicos parciais, CHECK, triggers no `audit_logs`, `pg_advisory_xact_lock`, jsonb, inet, `COALESCE` em unique), então a suíte roda em PostgreSQL, banco `<POSTGRES_DB>_test` (com `vector`), nunca em SQLite. O container traz `DB_CONNECTION`/`CACHE_STORE` de dev e o `$_SERVER` vence o `$_ENV` do PHPUnit; por isso `phpunit.xml` força os valores de teste também em `<server>`. Guarda em `Tests\TestCase::createApplication`: a suíte aborta se o driver não for `pgsql` ou o banco não terminar em `_test`. O banco é criado por `docker/postgres/create-test-db.sh` (init em volume novo; `make test-db` em volume existente). O health "up" usa o Redis real do compose.
- **Dev (override):** bind mount de `./backend` em `app`/`worker` com o `vendor` da imagem em volume nomeado `app_vendor`, rodando com `HOST_UID/HOST_GID` (default 1000) e `opcache.validate_timestamps=1` via `docker/laravel/php-dev.ini`. Após mudar `composer.json/lock`: rebuild + `docker volume rm <projeto>_app_vendor` (ou `make vendor-reset`). Base/prod inalterada.

## Notas de implementação (F1-07)

- **OpenAPI 3.0.0** gerada por `openapi:generate` (swagger-php 6.12.0 via l5-swagger 11.1.0, atributos PHP) e normalizada (JSON pretty, estável p/ diff) em `docs/api/openapi.json`. Servidor `/api/v1`, `bearerAuth` (http bearer), schemas `Envelope, Pagination, ErrorEnvelope, ValidationErrorEnvelope` e respostas reutilizáveis (`Unauthenticated, Forbidden, NotFound, Conflict, ValidationError, TooManyRequests, ServerError`). O arquivo é copiado para a imagem do app (`/var/www/docs/api`) e, em dev, `./docs` é montado no override para o gerador gravar no repositório.
- **Swagger UI** (`/api/documentation`, `/api/documentation/spec[/asset/*]`) só é registrado com `L5_SWAGGER_ENABLED=true` (config condicional → com `false` as rotas não existem, 404 no envelope). A UI serve o JSON versionado. No nginx o `location ^~ /api/documentation` com CSP própria (`script-src/style-src 'self' 'unsafe-inline'`, `img-src 'self' data:`) só é incluído pelo entrypoint `40-swagger.sh` quando a variável é true; `/` e o restante da API mantêm `default-src 'self'`.
- **Lint Redocly 2.60.0** (recommended): 0 erros. Avisos aceitos: `info-license-strict` (licença proprietária, sem URL); `operation-4xx-response` em `/health` (público, sem entrada que gere 4xx; responde 200/503); `no-unused-components` para as respostas reutilizáveis e `bearerAuth` — ficam disponíveis para os endpoints das próximas tarefas (auth, cadastros) e desaparecem à medida que forem referenciados.
- **Banco de teste só em dev:** `create-test-db.sh` agora é montado apenas no `docker-compose.override.yml` (o compose base/produção não cria `<POSTGRES_DB>_test`).

## Notas de implementação (F1-08 — audit trail)

- **Tabela e imutabilidade:** `audit_logs` (C.8) com `uuid`/`event_at` gerados pela aplicação; triggers `BEFORE UPDATE OR DELETE` (por linha) e `BEFORE TRUNCATE` (por statement) levantam exceção (`42501`, "audit_logs é append-only"). O model `AuditLog` (e seu builder) lança `AuditImmutableException` em update/delete/save de existente. Observação: um `UPDATE/DELETE` que não casa nenhuma linha não dispara o trigger por linha (não há o que proteger).
- **Hash encadeado:** `hash = sha256(prev_hash || canonical_json)`; `canonical_json` em `App\Support\Audit\CanonicalJson/AuditHasher` (15 campos, chaves ordenadas, `null` explícito, `event_at` ISO-8601 UTC com µs e `Z`, floats sem notação científica; campos JSON passam por round-trip e `[]` raiz é `{}`, para o hash bater com o que o `jsonb` devolve). Escrita serializada por `pg_advisory_xact_lock` dentro da transação; primeiro `prev_hash` = 64 zeros. `php artisan audit:verify` retorna exit ≠ 0 e o primeiro `id` divergente (conteúdo adulterado ou registro removido/reordenado).
- **Convenção de transação:** os observers do `Auditable` disparam após a escrita do model; para o log ser atômico com a mudança, as mutações devem rodar dentro de `DB::transaction()` (controllers/services de escrita). O `AuditService::record` abre transação própria se não houver.
- **Origem:** `source = http` quando a requisição passou pelo middleware `RequestId`; `queue` durante jobs (`Queue::before/after`); senão `console`.
- **Revoke de UPDATE/DELETE para o usuário de aplicação — pendência (inviável hoje):** a aplicação usa o mesmo usuário (`POSTGRES_USER`) que é superusuário e dono das tabelas; um `REVOKE` não o afeta (donos/superusuários podem desabilitar triggers). **Proposta:** dois papéis — `<slug>_owner` (migrations/DDL, usado só por `make migrate`/deploy) e `<slug>_app` (runtime: `SELECT, INSERT` em `audit_logs` e `USAGE` na sequence, sem `UPDATE/DELETE/TRUNCATE`, sem ownership), com `DB_USERNAME` de runtime ≠ usuário de migração. Fazer antes do deploy em produção (junto com a imagem `prod` enxuta). Enquanto isso a imutabilidade é garantida por triggers + `audit:verify` (tamper-evidence), não por privilégios.
- **Testes:** Pest em `<POSTGRES_DB>_test`, incluindo concorrência real com 2 processos PHP (`tests/Support/audit_writer.php`, 25 eventos cada, conexões e transações próprias): sem o advisory lock a cadeia bifurca (49≠50 `prev_hash` distintos); com o lock é válida.

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

## Notas de implementação (F1-12 — filiais e centros de custo; convenções de CRUD)

- **PUT = PATCH:** os dois verbos têm a mesma semântica **parcial**: no update só os campos enviados são validados (`sometimes`) e gravados; obrigatórios não podem vir vazios. A unicidade ignora o próprio registro. Base: `App\Http\Requests\ResourceRequest`.
- **Normalização do `code`:** trim + MAIÚSCULAS antes da validação (FormRequest) e na gravação (`HasNormalizedCode`), então a unicidade é, na prática, case-insensitive. `state` da filial em maiúsculas e validado contra as 27 UFs.
- **Listas (`App\Support\Api\ListQuery`):** `per_page` ≤ 100 (≤ 200 com `is_active=1`), `q` com ILIKE e `%`/`_`/`\` escapados (literais), `sort` multi-campo por whitelist (`-` = desc; padrão `code`; inválido → 422) com desempate por `id`, filtros planos validados (inválido → 422; desconhecidos são ignorados). `with_trashed=1` exige a ability `viewTrashed` da policy (= `*.manage`); sem ela → 403.
- **Excluídos:** show/update/delete de registro excluído → 404; só `POST /{id}/restore` enxerga excluídos (binding `withTrashed`). `deleted_at` está **sempre** presente na resposta (null quando ativo).
- **Exclusão (409):** "dependente ativo" = não soft-deleted, independentemente de `is_active`. Filial: centros de custo e usuários (equipamentos/colaboradores entram nas F1-14/15). A checagem e o soft delete rodam na mesma `DB::transaction`, com `SELECT … FOR UPDATE` na linha do pai; quem cria/move um filho (centro de custo) trava a filial com `FOR SHARE` e confirma que ela segue ativa, então as duas operações não se cruzam. Resposta 409 com `errors.dependents` (lista de tipos).
- **Restore (409):** se o registro não está excluído, se o `code` já foi reutilizado por um registro ativo, ou (centro de custo) se a filial vinculada está excluída.
- **Centros de custo:** `BranchScoped` com `branchScopeIncludesNull` (L vê os da própria filial + os sem filial; outra filial → 404). `branch_id` deve ser filial existente e não excluída; filial inativa é aceita.
- **OpenAPI:** parâmetros de lista reutilizáveis (`#/components/parameters/*`) ficam em `App\Support\Api\OpenApi\ListParameters`: declarados na classe `Spec`, faziam a geração perder os schemas dela. `/meta/enums` passou a devolver `branch_types`.

## Notas de implementação (F1-11 — usuários e auditoria)

- **CRUD `/users`** segue as convenções da F1-12 (`ListQuery`, `CrudActions`, PUT = PATCH, 409/restore, `with_trashed` só com `users.manage`). Resposta no schema `User` (`ManagedUserResource`), sem `password`/`remember_token`; o usuário de `/auth/*` continua no schema `AuthUser`. `User` **não** usa `BranchScoped` (alerta da F1-10, com teste): o filtro por filial é o parâmetro `branch_id`.
- **Filial:** `branch_id` é exigido quando o perfil resultante (enviado ou atual) não é admin; criar/alterar trava a filial com `FOR SHARE` na transação da gravação (`CrudActions::lockBranch`, que saiu do `CostCenterController` para a base). Filial excluída → 422.
- **Tokens:** trocar a senha de outro usuário, desativar ou excluir revoga todos os tokens do alvo. Quando o próprio admin troca a senha por `/users`, o token atual é mantido e os demais são revogados, como em `PUT /auth/password`. A troca de senha gera `password_changed` (`metadata.revoked_tokens`), porque o `updated` nunca leva a senha.
- **409:** o admin autenticado não exclui, não desativa e não muda o próprio `role`. Último admin ativo: antes de travar o alvo, as linhas de admin ativo não excluído são travadas `FOR UPDATE` em ordem de id (`softDeleteGuarded` ganhou um hook `$before` para isso), o que evita deadlock entre duas operações cruzadas e faz a segunda enxergar o resultado da primeira. Como o autenticado é sempre um admin ativo, o caso só ocorre em corrida; o teste simula o outro admin já desativado no banco.
- **Restore:** `restoreGuarded` passou a receber o campo único (`code` ou `email`); e-mail reutilizado ou filial excluída → 409.
- **`/audit-logs`:** somente leitura (`audit.view`). `auditable_type` usa o alias curto de `AuditLog::AUDITABLE_TYPES` (cada cadastro novo acrescenta o seu; um teste garante que todo model com `Auditable` tem alias). `action` fora do enum → 422. `from`/`to` são inclusivos; data sem hora cobre o dia inteiro em UTC, e a comparação mantém os microssegundos. Ordenação fixa `event_at desc, id desc` (`ListQuery::fixedOrder`; `sort` é ignorado). `actor` vem do snapshot gravado no evento (nome e perfil da época). `with_trashed` só existe para models com SoftDeletes.
- **409 fora do log de erro:** `DomainConflictException` entrou em `dontReport`, porque um conflito de regra é resposta esperada, não falha do servidor.
- `/meta/enums` devolve `roles`.

## Notas de implementação (F1-13 — famílias de equipamento)

- **Tabela** `equipment_families` conforme a C.4, com CHECKs no banco (category, criticality, `0 < preventive_lead_pct ≤ 100`, tolerâncias ≥ 0) e UQ parcial em `code`. A violação direta no SQL é rejeitada (teste).
- **Contrato v1.4, combinado com a Íris:** `preventive_lead_pct` tem cast `float` no model, porque o `numeric(5,2)` chega do PDO como string; a API sempre devolve número JSON. A validação aceita até 2 casas, igual à coluna. `tolerance_*` são inteiros ou null. No create, `criticality=medium` e `preventive_lead_pct=90` (iguais aos DEFAULT do banco, também em `$attributes`). `sort` ∈ code, name, category, criticality. `/meta/enums` ganha `equipment_categories` e `criticalities`. Sem escopo de filial (cadastro global; leitura M, L e A).
- **Dependentes:** `activeDependents()` já declara a chave `equipments`, mas a checagem fica desligada até a F1-15 criar a tabela.
- **Base (pendência da F1-12):** `softDeleteGuarded` responde 404 quando a linha travada já está excluída (duas exclusões concorrentes), sem excluir de novo e sem um segundo `deleted` no audit. Vale para todos os cadastros (teste por model).

## Notas de implementação (F1-33 — nome do sistema em fonte única, D17)

- **Fonte única:** `.env.example` define `APP_NAME` (nome curto), `APP_FULL_NAME` (nome completo) e `APP_SLUG` (identificador técnico); `POSTGRES_DB`/`POSTGRES_USER` usam o slug. Um rebrand é editar valores e os literais da allowlist, nunca caçar strings (passo a passo em `docs/renaming.md`).
- **Backend:** a marca fica em `config/app.php` (`name`, `full_name`, `slug`), e não num `config/brand.php` novo: o Laravel já lê `app.name`, e as três chaves ficam juntas sem outro arquivo de config. Defaults neutros do Laravel (`Laravel`/`laravel`), já que o compose sempre repassa as três variáveis. Prefixos de `cache`, `database.redis`, `session.cookie` e `horizon` derivam de `APP_SLUG` (antes `Str::slug(APP_NAME)`): trocar o nome exibido não muda chaves nem invalida sessões (teste `BrandConfigTest`).
- **OpenAPI:** atributos PHP não aceitam expressão, então `Spec` usa os marcadores `{app_name}`/`{app_full_name}` em `info.title`/`info.description`, e o `OpenApiBuilder` os preenche a partir do config antes de normalizar; o diretório temporário usa o slug. O `docs/api/openapi.json` versionado contém o nome atual (allowlist) e precisa ser regenerado (`make openapi`) num rebrand; o teste de "JSON sem diff" acusa se não for. O título da página do Swagger UI vem de `APP_NAME` (`l5-swagger.php`).
- **Infra:** `name: ${APP_SLUG:-…}` no compose, imagens `${APP_SLUG:-…}/<svc>:local`, rede `internal` e volumes sem `name:` (prefixo do projeto). As três variáveis vão para `app`, `worker`, `python`, `node` e como build args do nginx (o `vite.config.ts` mapeia `APP_*` → `VITE_APP_*`). No override, o `frontend` recebe `APP_*` vazios por padrão, caindo nos defaults do `brand.ts`. `create-test-db.sh` cria `${POSTGRES_DB}_test`; o `backend/phpunit.xml` força esse nome como literal (o XML não interpola) e por isso está na allowlist. O Makefile lê o slug do `.env` (`vendor-reset`).
- **Serviços:** título do FastAPI = `${APP_NAME} python-ai`; `package.json` do node-realtime = `<slug>-node-realtime`.
- **Guarda:** `scripts/check-brand.sh` lê os três valores do `.env.example` e varre os arquivos versionados: nome curto por palavra inteira com caixa, nome completo exato, slug por palavra inteira sem caixa (pega `<slug>_`, `<slug>-`, `<slug>/`, `<slug>.`). Fora da allowlist, sai 1. O nome anterior (padrão montado sem o literal) só é aceito na linha D17 deste ADR. Também falha se uma entrada da allowlist não existir mais. `scripts/check-brand.test.sh` cobre os casos negativos (slug, nome curto, nome completo e nome antigo injetados fora da allowlist → exit ≠ 0) e o positivo. Ligada em `make brand`/`make lint`/`make ci` e no workflow inativo `.github/workflows/ci.yml` (`workflow_dispatch`, completado na F1-19).
