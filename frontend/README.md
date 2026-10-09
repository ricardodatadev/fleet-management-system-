# Frontend

Vite + React 18 + TypeScript (strict) · Tailwind 4 · React Router 6 · TanStack Query · Vitest/RTL/MSW/jest-axe.
O host não tem Node: rode via container (Node 24 LTS), a partir da raiz do repositório:

```bash
N="docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v $PWD:/repo -w /repo/frontend node:24.21.0-alpine"
$N npm ci
$N npm run ci        # lint + format:check + typecheck + test + build
$N npm run gen:api   # ../docs/api/openapi.json -> src/api/schema.d.ts (commitado)
```

- **Dev (HMR):** serviço `frontend` do `docker-compose.override.yml` → http://localhost:5173 (proxy de `/api` para o nginx).
- **Build/produção:** `docker/nginx/Dockerfile` compila a SPA (multi-stage) e serve `dist/` no nginx.
- **Cliente HTTP:** `src/api/client.ts` (`api`) — base `/api/v1`, Bearer opcional, desembrulha o envelope, erros como `ApiError{status,message,errors}` (`status 0` = rede indisponível).
- **Nome do sistema:** `src/config/brand.ts` (`APP_NAME`, `APP_FULL_NAME`, `APP_SLUG`) é a única fonte no front; os valores vêm de `VITE_APP_*`, que o `vite.config.ts` preenche a partir de `APP_*` (fallback nos defaults do módulo). Chave de sessão e `device_name` derivam do slug. Trocar o nome: `docs/renaming.md`.
- **Tokens (G.1):** `src/lib/tokens.ts` (`TAP_MIN=48`) e `src/styles/index.css`.
