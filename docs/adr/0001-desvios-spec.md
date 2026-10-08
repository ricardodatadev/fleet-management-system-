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
| D11 | Node 20 | Node LTS ativa vigente para `node` e build do frontend. Python 3.12, PostgreSQL 16, Redis 7, React 18 mantidos. |
| D12 | Horizon | `/horizon` não exposto pelo nginx na Fase 1; verificação via `horizon:status`. |
| D13 | Papéis Almoxarife/Financeiro (matriz §5 SRS) | Fora de RN-007; ficam para fase própria. Enum de perfis extensível. |
| D14 | RN-002 (matriz por Categoria de Serviço) | `workorder.block_close_without_labor` é placeholder (bool global/filial); a RN-002 real será modelada na fase de OS, substituindo a chave. RN-001 "Modo de Disparo" está adequada. |
| D15 | Token Bearer em `localStorage` | Aprovado na Fase 1 com CSP estrita. **Risco:** XSS pode roubar o token. Revisão obrigatória na fase do PWA/offline (avaliar cookie httpOnly/OIDC). |
| D16 | Git | Repositório e fluxo pertencem ao Escrivão. Senior/Íris não executam `git init`, não criam branches de integração nem fazem merge. |

## Nota adicional (CSP)

CSP estrita (`default-src 'self'`) em `/`; `/api/documentation*` e `/vendor/l5-swagger/` têm CSP própria que permite o Swagger UI (`'unsafe-inline'` em script/style, `img-src 'self' data:`), e esse `location` só existe com `L5_SWAGGER_ENABLED=true`.

## Versões

Pesquisa feita em 2026-10-08 (busca web; **não** verificada em packagist/nodejs.org — confirmar nas tarefas indicadas).

| Componente | Registro atual | Confirmação |
|---|---|---|
| Laravel (D1) | Série **13.x** é a última estável (13.8.0 de 2026-05-26 segundo agregadores); suporta PHP 8.3–8.5, mínimo 8.3. Suporte ativo até 2027-09-30, segurança até 2028-03-17. | **a confirmar em F1-03** (`composer create-project` / packagist; versão exata + PHP) |
| PHP | mínimo 8.3 (exigido pelo Laravel 13); versão da imagem a definir | a confirmar em F1-03 |
| Sanctum / Horizon / Pest / Pint / Larastan / l5-swagger | compatibilidade com Laravel 13 não verificada | a confirmar em F1-03/F1-07/F1-19 |
| Node (D11) | Em 2026-10-08 a **24 (Krypton) é a LTS ativa**; a **26 entra em LTS em 2026-10-20** (EOL abr/2029); a 24 sai de Active LTS em 2026-10-20 (segue em Maintenance). | **a confirmar em F1-05** (decidir 24 vs 26 conforme data) |
| Python 3.12, PostgreSQL 16, Redis 7, React 18 | conforme spec | tags exatas a fixar em F1-02/F1-04/F1-21 |

## Pendências de decisão registradas

- Q7: remoto git não confirmado; workflow GitHub Actions fica inativo, o gate é o `make ci` local.
- Usuário de aplicação do banco sem `UPDATE/DELETE` em `audit_logs` (F.1): se inviável na Fase 1, documentar aqui na F1-08.
