# Trocar o nome do sistema

O nome do sistema vem de **uma fonte única**: três variáveis no `.env` (modelo no `.env.example`).

| Variável | Uso | Exemplo de formato |
|---|---|---|
| `APP_NAME` | nome curto exibido (título da API/OpenAPI, Swagger UI, FastAPI, `<title>`, login, topbar) | sigla em maiúsculas |
| `APP_FULL_NAME` | nome completo exibido (descrição da OpenAPI, login, `<meta name="description">`) | entre aspas se tiver espaço |
| `APP_SLUG` | **identificador técnico**: projeto do compose, imagens, rede, volumes, banco (`POSTGRES_DB`/`POSTGRES_USER`), prefixos de cache/Redis/sessão/Horizon, chave de sessão e device name do front | minúsculas, `[a-z0-9-]` |

Nenhum código lê o nome de outro lugar. O que não interpola variáveis (JSON, XML, defaults de fallback) é literal e está listado na [allowlist](#allowlist). A guarda `scripts/check-brand.sh` (`make brand`, também em `make lint`/`make ci`) falha se o nome aparecer em qualquer outro arquivo versionado.

## Passo a passo

1. **Antes de trocar**, derrube a stack atual: `make down` (o projeto do compose, a rede e os volumes mudam de nome com o slug).
2. Edite os três valores no `.env.example` **e** no seu `.env` local. Atualize também `POSTGRES_DB` e `POSTGRES_USER` (por convenção, iguais ao novo slug).
3. Atualize os literais dos arquivos da [allowlist](#allowlist) (são os únicos):
   - `docker-compose.yml`: defaults `${APP_NAME:-…}`, `${APP_FULL_NAME:-…}` e `${APP_SLUG:-…}` (fallback se o `.env` não definir);
   - `backend/phpunit.xml`: `DB_DATABASE` = `<POSTGRES_DB>_test` (nas linhas `<env>` e `<server>`);
   - `backend/composer.json`: `name` = `<slug>/backend`;
   - `services/python-ai/app/main.py`: default de `APP_NAME`;
   - `services/node-realtime/package.json` e `package-lock.json`: `name` = `<slug>-node-realtime`;
   - `frontend/src/config/brand.ts`: `BRAND_DEFAULTS`;
   - `frontend/package.json` e `package-lock.json`: `name` = `<slug>-frontend`;
   - `README.md`: título;
   - `docs/adr/0001-desvios-spec.md`: registre o rebrand numa entrada nova (a anterior vira histórico; ajuste a regra do nome antigo em `scripts/check-brand.sh` se for o caso).
4. Suba o ambiente com o nome novo (ver [Ambiente local](#ambiente-local)).
5. Regenere a OpenAPI: `make openapi` (o título e a descrição vêm do config). O teste "JSON sem diff" falha até isso ser feito.
6. Rode a guarda e a suíte: `make brand` e `make ci`.

Nenhum outro arquivo precisa mudar. Se a guarda apontar uma ocorrência fora da allowlist, troque o literal por leitura do config/ambiente; só inclua um arquivo novo na allowlist se o formato não permitir interpolação, e sempre com o motivo.

## Allowlist

Arquivo: `scripts/brand-allowlist.txt` (um caminho por linha, com o motivo após `#`).

| Arquivo | Motivo |
|---|---|
| `.env.example` | fonte única (define os três valores e `POSTGRES_DB`/`POSTGRES_USER`) |
| `docker-compose.yml` | fallback da infra (`${APP_*:-…}`) quando o `.env` não define a marca |
| `backend/phpunit.xml` | `DB_DATABASE` forçado como `<slug>_test`; o XML do PHPUnit não interpola env |
| `backend/composer.json` | nome do pacote composer; JSON não interpola |
| `docs/api/openapi.json` | artefato gerado por `make openapi` a partir de `app.name`/`app.full_name` |
| `services/python-ai/app/main.py` | default de `APP_NAME` no título do FastAPI |
| `services/node-realtime/package.json`, `package-lock.json` | nome do pacote npm |
| `frontend/src/config/brand.ts` | defaults do front (único módulo com o nome em `src/`) |
| `frontend/package.json`, `package-lock.json` | nome do pacote npm |
| `README.md` | título e apresentação do projeto |
| `docs/adr/0001-desvios-spec.md` | entrada D17, registro histórico do rebrand |

A guarda também falha se uma entrada da allowlist não existir mais, e se o nome anterior aparecer em qualquer lugar fora da linha D17 do ADR-0001 (o padrão é montado no script sem o literal nem o slug atual).

## Regras da guarda

- `APP_NAME`: palavra inteira, diferenciando maiúsculas e minúsculas.
- `APP_FULL_NAME`: texto exato.
- `APP_SLUG`: palavra inteira, sem diferenciar caixa; `_`, `-`, `/` e `.` contam como separador (pega `<slug>_test`, `<slug>-frontend`, `<slug>/app`).
- Teste da guarda: `bash scripts/check-brand.test.sh` (casos injetados fora da allowlist precisam sair ≠ 0).

## Ambiente local

Projeto, rede (`<slug>_internal`), volumes (`<slug>_db_data`, `<slug>_redis_data`, `<slug>_app_vendor`) e imagens (`<slug>/<svc>:local`) mudam de nome com o slug. Os dados locais **não** migram; são recriados pelas migrations e seeders.

```bash
make down                                  # 1. ANTES de editar o .env (ainda com o slug antigo)
# 2. edite os 3 valores (e POSTGRES_DB/POSTGRES_USER) no .env
docker compose -p <slug-antigo> down -v    # 3. remove containers, rede e volumes do projeto antigo, se ainda existirem
make up init                               # 4. sobe o projeto novo, gera APP_KEY se faltar e migra
make test-db                               # 5. cria <POSTGRES_DB>_test (idempotente)
docker compose ls; docker volume ls        # 6. confira o prefixo novo
```

- Um `.env` antigo **precisa** receber os três valores novos; sem eles o compose cai nos defaults do `docker-compose.yml`.
- **Sessão do front:** a chave de storage (`<slug>.session`) e o device name (`<slug>-web`) mudam com o slug, então os usuários logados são deslogados **uma vez**. As sessões e o cache do Laravel também recomeçam (novo prefixo no Redis).
- Trocar só `APP_NAME`/`APP_FULL_NAME` (sem mudar o slug) não muda projeto, volumes, chaves nem sessões: basta rebuildar (`make up`) e regenerar a OpenAPI.
