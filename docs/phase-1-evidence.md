# Evidências da Fase 1

Documento do gate da Fase 1 (spec, seção I). Consolidado na F1-20: o mapa do DoD está na seção Backend;
cada área mantém a sua seção.

## Backend e infraestrutura (F1-01 a F1-20, F1-33b, F1-34; gate F1-20)

Gerado em 2026-10-10, na branch `feat/f1-20-smoke-evidence-docs` (base develop `bc9d61c`). Saídas reais, copiadas dos comandos indicados; `<slug>` = `APP_SLUG` do `.env`.

### Mapa do DoD (seção I da spec) → evidência

| Item | Evidência |
|---|---|
| 1. Tarefas | Checklist da fase (`.maestri/specs/fase-1/checklist.md`, do planejamento, fora do git; fonte única do status): F1-01..31 e F1-33..36 com `[x]` (hash do merge, data, push ok), cada uma auditada pelo Claudão e testada pelo usuário; F1-32 `[~]` adiada para a Fase 2 (decisão Q1). A F1-20 fecha a lista ao entrar em `develop` |
| 2. Execução do zero | Smoke E2E abaixo: clone limpo + `.env` do `.env.example` + só o compose base, sai 0 |
| 3. Containers | `docker compose ps` (8 healthy), `docker compose -f docker-compose.yml config` (só nginx com `ports`), `\dx` (vector 0.8.1); smoke passos 2, 5 e 6 |
| 4. Segurança de configuração | Varredura de segredos e `.env` ignorado; compose sem `version` e sem credencial literal; tags fixas; smoke passos 15 (APP_DEBUG=false não vaza) e 16 (não-root) |
| 5. API | `EnvelopeTest` (401/403/404/405/409/422/429/500 no envelope, 500 sem trace com APP_DEBUG=false), `OpenApiTest` (OpenAPI 3.0.x, 100% das rotas `api/v1` documentadas, sem rota inexistente, JSON versionado igual ao gerado) e `make openapi-lint` (0 erros) |
| 6. RBAC | Matriz da seção E em 2 camadas: `PermissionMatrixTest` (Gates: cada perfil O/M/L/A × cada linha da matriz, transcrita literalmente e independente do `config/rbac.php`, que tem de ser exatamente igual) e as rotas: `RouteAuthorizationSweepTest` varre toda rota `api/v1` e exige `auth:sanctum` + `can:<permissão da matriz>` (fora a allowlist pública), com prova negativa de que uma rota sem permissão reprova; mais os 403 por perfil nos testes HTTP de cada CRUD. Escopo de filial: `BranchScopeTest` (L/M/O só a própria filial e 404 na outra, admin vê tudo, 403 antes do 404, policy por instância com o mesmo escopo) |
| 7. Audit trail | `AuditTrailTest`, `AuditImmutabilityTest` (UPDATE/DELETE/TRUNCATE via SQL cru falham), `AuditVerifyTest`; `audit:verify` = 0 no dev e no smoke (passos 10 e 11) |
| 8. Settings | `SettingsTest`: as 4 chaves RN-001..004 no registry (RN-002 placeholder, D14), precedência family > branch > global > default com a origem, unicidade por escopo, global não removível; "sem motor de regra" é um teste: as chaves e o `SettingsService` só são usados pela API de parâmetros. Tela: Painel de Parâmetros (F1-28, seção Frontend) |
| 9. Frontend via nginx | Seção Frontend abaixo; no smoke, a SPA e a API saem pelo mesmo nginx (único serviço publicado) |
| 10. Node/Python | Smoke passos 12 (Socket.io pela porta pública do nginx: válido → `session:ready`; sem token e inválido → `unauthorized`), 13 e 14 (`/health`); Vitest do node 17/17 e pytest 3/3 |
| 11. Qualidade (backend) | `make ci` abaixo: Pint, Larastan nível 5, Pest 696 testes com 96,6% de cobertura, pytest, Vitest, openapi-lint |
| 12. Documentação | README (quickstart, arquitetura, alvos, troubleshooting), [ADR-0001](adr/0001-desvios-spec.md) (D1–D17 + versões reais reconferidas na F1-20), [`docs/renaming.md`](renaming.md), este arquivo |
| 13. Higiene git (D16) | Branches `feat/f1-XX-slug` saem de `develop` e entram por `merge --no-ff` feito pelo Escrivão, com `git push origin develop` (nunca force) só após a aprovação do Claudão e o OK do usuário ao roteiro de teste (registrado no checklist como "push ok"); commits convencionais, sem menção a ferramentas de IA nem linhas de co-autoria. `main` + tag `v0.1.0-phase1` só depois da auditoria final e do OK do usuário |
| 14. Fora de escopo | O banco da fase tem só as 12 migrations da F1 (tabelas `audit_logs`, `branches`, `users`, `personal_access_tokens`, `cost_centers`, `equipment_families`, `employees`, `password_reset_tokens`, `equipments` e `settings`, mais 2 alterações em `users` para o username); nenhuma tabela, rota ou tela de OS, preventiva, estoque, pneus, checklist, solver, RAG, BI ou telemetria. A `RouteAuthorizationSweepTest` e o `OpenApiTest` amarram as rotas existentes às documentadas; os itens futuros da sidebar ficam desabilitados ("Em breve") |
| 15. Nome do sistema | `check-brand: ok` (primeira etapa do `make ci`) |

### `make ci` (gate local)

Rodado na stack de dev (imagem de dev do `app`, com pcov) a partir da raiz do repositório. Saída 0. Ordem: `lint` → `test` → `test-python` → `test-node` → `openapi-lint` (este último entrou no `ci` na F1-20).

| Etapa | Resultado |
|---|---|
| `check-brand.sh` | `check-brand: ok (444 arquivo(s), 13 na allowlist)` |
| `check-brand.test.sh` | 8/8 `ok` (slug, slug em outra caixa, nome curto, nome completo e nome antigo fora da allowlist → exit 1; casos positivos → exit 0) |
| `compose.test.sh` | `compose.test: ok (8 serviços; só o nginx publica porta; scheduler com schedule:work, sem porta, não-root, healthcheck por batimento)` |
| Pint (`pint --test`) | `PASS ... 182 files` |
| Larastan nível 5, sem baseline | `[OK] No errors` |
| Pest (`--coverage --min=80`, PostgreSQL, banco `_test`) | `Tests: 696 passed (3352 assertions)`, `Duration: 169.34s` |
| Cobertura de `app/` (pcov) | **`Total: 96.6 %`** (gate ≥ 80%) |
| pytest (`services/python-ai`, alvo `test`) | `3 passed, 1 warning in 1.03s` (aviso de depreciação do `starlette.testclient`) |
| Vitest (`services/node-realtime`, alvo `test`) | `Test Files 2 passed (2)`, `Tests 17 passed (17)` |
| `openapi-lint` (`@redocly/cli@2.60.0`) | `Woohoo! Your API description is valid.` · `You have 2 warnings.` |

Os 2 avisos do Redocly são aceitos (ADR-0001): `info-license-strict` (licença `Proprietary`, sem URL) e `operation-4xx-response` em `/health` (público, sem 4xx; responde 503 quando db ou redis caem).

### Containers (`docker compose ps`, stack de dev)

Os 8 serviços do compose base `healthy`; `frontend` (Vite, sem healthcheck) e `mailpit` existem só no override de dev, que também publica db/redis/node/python em `127.0.0.1`.

```
SERVICE     IMAGE                          STATUS                  PORTS
app         <slug>/app:local               Up 10 hours (healthy)   9000/tcp
db          pgvector/pgvector:0.8.1-pg16   Up 10 hours (healthy)   127.0.0.1:5432->5432/tcp
frontend    node:24.21.0-alpine            Up 10 hours             127.0.0.1:5173->5173/tcp
mailpit     axllent/mailpit:v1.31.4        Up 10 hours (healthy)   1025/tcp, 1110/tcp, 127.0.0.1:8025->8025/tcp
nginx       <slug>/nginx:local             Up 10 hours (healthy)   0.0.0.0:8080->80/tcp, [::]:8080->80/tcp
node        <slug>/node-realtime:local     Up 10 hours (healthy)   127.0.0.1:3000->3000/tcp
python      <slug>/python-ai:local         Up 10 hours (healthy)   127.0.0.1:8000->8000/tcp
redis       redis:7.4.6-alpine             Up 10 hours (healthy)   127.0.0.1:6379->6379/tcp
scheduler   <slug>/app:local               Up 10 hours (healthy)   9000/tcp
worker      <slug>/app:local               Up 10 hours (healthy)   9000/tcp
```

No smoke (só o compose base, `APP_ENV=production`): `8/8 healthy: app,db,nginx,node,python,redis,scheduler,worker`, e só o nginx com porta publicada.

### Portas do compose base (`docker compose -f docker-compose.yml config`)

Serviços e o campo `ports` de cada um, extraídos de `docker compose -f docker-compose.yml config --format json`:

```
app None
db None
nginx [{'mode': 'ingress', 'target': 80, 'published': '8080', 'protocol': 'tcp'}]
node None
python None
redis None
scheduler None
worker None
```

### pgvector (`\dx` no banco de dev)

```
                             List of installed extensions
  Name   | Version |   Schema   |                     Description
---------+---------+------------+------------------------------------------------------
 plpgsql | 1.0     | pg_catalog | PL/pgSQL procedural language
 vector  | 0.8.1   | public     | vector data type and ivfflat and hnsw access methods
(2 rows)
```

### Segurança de configuração

- **`.env` ignorado:** `git check-ignore -v .env` → `.gitignore:5:.env	.env`; `git ls-files` não lista nenhum `.env`.
- **Varredura de segredos:** `git grep -nIiE "(password|passwd|secret|api[_-]?key|token)\s*[:=]\s*['\"]?[A-Za-z0-9+/_\-]{8,}"` nos arquivos versionados (fora lockfiles e artefatos gerados), descartando `change-me`, `${VAR}`, `env()`/`config()` e testes, encontra só `UserFactory::PASSWORD` (senha das factories, usada apenas nos testes) e um comentário do frontend. Chaves privadas, `APP_KEY` (`base64:` de 40+ caracteres), chaves AWS (`AKIA…`) e tokens do GitHub (`ghp_…`): nenhuma ocorrência.
- **Compose:** sem chave `version`; toda senha vem do `.env` com erro se faltar (`${POSTGRES_PASSWORD:?…}`, `${REDIS_PASSWORD:?…}`); o redis recebe o `requirepass` por um arquivo gerado no start com `umask 077`; os `SEED_*_PASSWORD` não têm default.
- **Tags fixas:** `pgvector/pgvector:0.8.1-pg16`, `redis:7.4.6-alpine`, `axllent/mailpit:v1.31.4`, `node:24.21.0-alpine`; nos Dockerfiles, `php:8.4.26-fpm-alpine`, `composer:2.10.3`, `nginx:1.30.5-alpine`, `python:3.12.15-slim`, `node:24.21.0-alpine`. As imagens próprias são `<slug>/<serviço>:local`, construídas pelo compose (`pull_policy: never` em worker/scheduler).
- **Não-root e APP_DEBUG=false:** comprovados no smoke (passos 15 e 16) e no `EnvelopeTest` (500 sem trace/SQL/caminhos com `APP_DEBUG=false`).

### Audit trail (`php artisan audit:verify`)

No banco de dev: `Cadeia íntegra: 423 registro(s) verificados.` (exit 0). No smoke, num banco novo: `Cadeia íntegra: 10 registro(s) verificados.` (exit 0).

### Smoke E2E (`bash scripts/smoke.sh`)

Clone limpo do commit `e526f2c` (HEAD com o próprio smoke), `.env` de produção com segredos aleatórios, só o `docker-compose.yml`, projeto do compose próprio e nginx numa porta livre; a stack de dev não foi tocada. Saída 0, e no fim não sobrou container, volume, imagem nem diretório do smoke.

| # | Verificação | Resultado | Detalhe |
|---|---|---|---|
| 1 | clone limpo | PASS | git clone de e526f2c + .env do .env.example (production, APP_DEBUG=false, segredos aleatórios) |
| 2 | 8 serviços healthy | PASS | 8/8 healthy: app,db,nginx,node,python,redis,scheduler,worker |
| 3 | migrate + seed | PASS | migrate --force e db:seed --force (admin smoke.admin + 4 parâmetros globais) |
| 4 | scheduler com batimento recente | PASS | batimento há 4s (< 150 s); prune-expired agendado: 1 |
| 5 | pgvector | PASS | extensão vector 0.8.1 |
| 6 | só o nginx publica porta | PASS | config: nginx · em execução: nginx |
| 7 | health da API (nginx) | PASS | app/db/redis up |
| 8 | login por username | PASS | smoke.admin (caixa mista e espaços) → token Bearer |
| 9 | cria família + equipamento | PASS | filial 1, centro de custo 1, família 1, equipamento 1 (SMK0001, criticidade high (family)) |
| 10 | audit-logs com os eventos | PASS | login_succeeded + created de filial, centro de custo, família e equipamento |
| 11 | audit:verify | PASS | exit 0 · Cadeia íntegra: 10 registro(s) verificados. |
| 12 | Socket.io pelo nginx | PASS | token válido → session:ready (ready user:1,role:admin); sem token e inválido → unauthorized |
| 13 | python /health | PASS | {"status":"ok","service":"python-ai","version":"0.1.0"} |
| 14 | node /health | PASS | {"status":"ok","service":"node-realtime","redis":"up"} |
| 15 | erro sem vazar stack | PASS | 404 no envelope, sem trace/exception/caminho: {"status":"error","message":"Recurso não encontrado.","errors":null,"data":null} |
| 16 | aplicação não-root | PASS | uid: app=82 worker=82 scheduler=82 python=10001 node=1000 |

`SMOKE PASS: 16/16 verificações (commit e526f2c).`

## Frontend (F1-21 a F1-31, F1-33a, F1-35, F1-36; gate F1-29)

Gerado em 2026-10-10, na branch `feat/f1-29-frontend-quality`, a partir do develop `d17a03b`.

### `npm run ci` (gate)

Rodado no container `node:24.21.0-alpine`, em `frontend/`. Saída 0.

| Etapa | Resultado |
|---|---|
| ESLint (`--max-warnings=0`, inclui jsx-a11y, react-hooks e `no-explicit-any`) | 0 erros, 0 warnings |
| Prettier (`--check`) | todos os arquivos formatados |
| TypeScript (`tsc -b`, `strict`, sem `any` implícito) | 0 erros |
| Vitest + cobertura (`test:coverage`, thresholds de 70%) | 51 arquivos, 471 testes verdes |
| Build (`tsc -b && vite build`) | ok |
| Bundle inicial (`check:bundle`, limite 500 kB) | 190,09 kB (JS 142,8 kB gz + CSS 5,7 kB gz + fontes woff2 41,6 kB) |

### Cobertura (Vitest, `src/`)

Relatório completo em `frontend/coverage/` (html e json-summary, gerado pelo `npm run test:coverage`, fora do git).

| Métrica | Cobertura | Gate |
|---|---|---|
| Linhas | 97,69% (1230/1259) | ≥ 70% |
| Statements | 96,40% (1342/1392) | ≥ 70% |
| Funções | 96,68% (496/513) | ≥ 70% |
| Branches | 90,76% (1170/1289) | ≥ 70% |

### Acessibilidade, toque e teclado

- **axe, 0 violações:** Login, Equipamentos (shell + lista e o Drawer 360°) e Parâmetros, em `src/quality/a11y.test.tsx`. Cada tela e componente também tem o seu teste com axe.
- **Alvos de 48px:** `src/components/ui/tap-min.test.tsx` e asserções `min-h-12 min-w-12` nos testes das telas.
- **Espaçamento ≥ 8px entre alvos (G.1):** `src/quality/spacing.test.tsx` verifica shell desktop e mobile (com o menu aberto), toolbar e ações de tabela de Equipamentos, formulário de equipamento, modal de cadastro, Painel de Parâmetros e Login. A prova negativa (sem o `gap-2` das ações de linha) falha.
- **Teclado:** Login, menus, abas, Drawer (foco preso, Esc, foco devolvido), Wizard/Stepper, Toast e Select, além do RadioGroup do Painel de Parâmetros (conferido com teclas reais no Chrome nos smokes).

### Segurança das dependências (`npm audit`)

0 high e 0 critical. Os 2 alertas moderados do `react-router` 6 não têm correção na linha 6 (só na 7.18); a decisão e a mitigação (`safeNext`, com testes) estão no [ADR-0002](adr/0002-frontend-stack.md#risco-react-router-6-npm-audit).

### Sessão

Se o `/auth/me` falhar logo após um login bem-sucedido, o token recém-emitido é revogado (`POST /auth/logout` com ele, best effort) antes de o erro aparecer, e nada vai para o storage (`auth.test.tsx`).

### Capturas do shell (API real)

Tela inicial (Frotas & Equipamentos) contra a stack local, com usuários temporários removidos depois. O admin vê todas as filiais, o filtro de filial e as ações de escrita; o mecânico vê só a própria filial, sem filtro de filial nem escrita, e a sidebar segue as permissões dele.

| Largura | Admin | Mecânico |
|---|---|---|
| 1280 px (sidebar fixa) | ![Shell do admin, 1280 px](evidence/f1-29/shell-admin-1280px.png) | ![Shell do mecânico, 1280 px](evidence/f1-29/shell-mecanico-1280px.png) |
| 768 px (sidebar em ícones) | ![Shell do admin, 768 px](evidence/f1-29/shell-admin-768px.png) | ![Shell do mecânico, 768 px](evidence/f1-29/shell-mecanico-768px.png) |
| 375 px (menu no drawer) | ![Shell do admin, 375 px](evidence/f1-29/shell-admin-375px.png) | ![Shell do mecânico, 375 px](evidence/f1-29/shell-mecanico-375px.png) |

### Smokes contra a API real (por tarefa)

Cada tela foi validada contra a stack local antes do merge. Os registros criados nos testes usaram prefixo próprio e foram removidos, e os dados do usuário não foram alterados (conferido com snapshot ou assinatura antes e depois).

| Tarefa | Resultado |
|---|---|
| F1-30 Unidades, Centros de Custo, Famílias | 35/35 |
| F1-31 Colaboradores e Usuários | 19/19 |
| F1-25 Listagem de equipamentos | 15/16 (o 1 restante era conta errada do script; o comportamento estava certo) |
| F1-26 Formulário de equipamento | 11/11 |
| F1-28 Painel de Parâmetros | 18/18, com `/settings` igual antes e depois |
| F1-27 Drawer 360° | 15/15, somente leitura, com a assinatura dos equipamentos igual antes e depois |
