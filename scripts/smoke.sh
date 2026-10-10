#!/usr/bin/env bash
# Smoke E2E da Fase 1 (F1-20): sobe a stack do zero num CLONE LIMPO isolado e verifica o fluxo de ponta
# a ponta, imprimindo uma tabela PASS/FAIL por verificação. Não toca na stack de dev nem no banco dela.
#
#   bash scripts/smoke.sh            # roda e limpa tudo no fim (containers, volumes, rede, imagens, clone)
#   SMOKE_KEEP=1 bash scripts/smoke.sh   # mantém a stack do smoke de pé para inspeção (limpe depois)
#
# Como funciona: `git clone` do commit atual (HEAD; mudanças não commitadas ficam de fora) num diretório
# temporário; .env a partir do .env.example com segredos aleatórios, APP_ENV=production e APP_DEBUG=false;
# só o compose BASE (docker-compose.yml, o de produção) num projeto do compose próprio, com o nginx numa
# porta livre do host. Requisitos no host: docker (com compose), git, curl e python3.
set -uo pipefail

SRC="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
HEAD_REF="$(git -C "$SRC" rev-parse --short HEAD)"
SLUG_BASE="$(sed -n 's/^APP_SLUG=//p' "$SRC/.env.example" | tail -n 1)"
PROJECT="${SLUG_BASE}smoke$$"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/smoke-XXXXXX")"
CLONE="$WORK/repo"
PORT="$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1]); s.close()')"
BASE="http://127.0.0.1:$PORT"
API="$BASE/api/v1"
LOG="$WORK/smoke.log"
J=(-H 'Accept: application/json' -H 'Content-Type: application/json')

RESULTS=()
FAILED=0
record() { # record <PASS|FAIL> <verificação> <detalhe>
  RESULTS+=("$1|$2|$3")
  [ "$1" = PASS ] || FAILED=$((FAILED + 1))
  printf '  %-4s %s — %s\n' "$1" "$2" "$3"
}
check() { # check <verificação> <detalhe> <comando...>: PASS se o comando sai 0
  local name="$1" detail="$2"; shift 2
  if "$@" >>"$LOG" 2>&1; then record PASS "$name" "$detail"; else record FAIL "$name" "$detail (ver $LOG)"; fi
}
dc() { docker compose -p "$PROJECT" -f "$CLONE/docker-compose.yml" --env-file "$CLONE/.env" --project-directory "$CLONE" "$@"; }
json() { python3 -c "import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1]))" "$1"; }

cleanup() {
  if [ "${SMOKE_KEEP:-0}" = 1 ]; then
    echo "SMOKE_KEEP=1: stack '$PROJECT' mantida em $CLONE (nginx em $BASE). Para limpar: docker compose -p $PROJECT down -v --rmi local; rm -rf $WORK"
    return
  fi
  dc down -v --remove-orphans --rmi local >/dev/null 2>&1
  docker images --format '{{.Repository}}:{{.Tag}}' | grep "^${PROJECT}/" | xargs -r docker rmi -f >/dev/null 2>&1
  # o clone pode ter arquivos criados pelos containers: remove por dentro de um container
  docker run --rm -v "$WORK:/w" alpine:3.22 sh -c 'rm -rf /w/repo' >/dev/null 2>&1
  rm -rf "$WORK"
}
trap cleanup EXIT

echo "Smoke E2E — commit $HEAD_REF · projeto $PROJECT · nginx em $BASE"
echo

# 1. Clone limpo + .env (segredos aleatórios; produção; seed do admin)
secret() { printf 'Sm%sA1' "$(openssl rand -hex 12 2>/dev/null || python3 -c 'import secrets; print(secrets.token_hex(12))')"; }
ADMIN_USER=smoke.admin
ADMIN_PASS="$(secret)"
if git clone -q "$SRC" "$CLONE" >>"$LOG" 2>&1 && cp "$CLONE/.env.example" "$CLONE/.env"; then
  sed -i \
    -e "s|^APP_ENV=.*|APP_ENV=production|" -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
    -e "s|^APP_SLUG=.*|APP_SLUG=$PROJECT|" -e "s|^WEB_HTTP_PORT=.*|WEB_HTTP_PORT=$PORT|" \
    -e "s|^APP_URL=.*|APP_URL=$BASE|" \
    -e "s|^POSTGRES_PASSWORD=.*|POSTGRES_PASSWORD=$(secret)|" -e "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=$(secret)|" \
    -e "s|^SEED_ADMIN_USERNAME=.*|SEED_ADMIN_USERNAME=$ADMIN_USER|" -e "s|^SEED_ADMIN_EMAIL=.*|SEED_ADMIN_EMAIL=smoke.admin@example.com|" \
    -e "s|^SEED_ADMIN_PASSWORD=.*|SEED_ADMIN_PASSWORD=$ADMIN_PASS|" -e "s|^SEED_DEMO_PASSWORD=.*|SEED_DEMO_PASSWORD=$(secret)|" \
    "$CLONE/.env"
  record PASS "clone limpo" "git clone de $HEAD_REF + .env do .env.example (production, APP_DEBUG=false, segredos aleatórios)"
else
  record FAIL "clone limpo" "git clone falhou (ver $LOG)"
fi

# 2. Build + APP_KEY + up (só o compose base) com os 8 serviços healthy
echo "  ...  build e subida da stack (alguns minutos)"
if dc build >>"$LOG" 2>&1; then
  KEY="$(dc run --rm --no-deps -T app php artisan key:generate --show 2>>"$LOG" | tail -n 1)"
  sed -i "s|^APP_KEY=.*|APP_KEY=$KEY|" "$CLONE/.env"
  if dc up -d --wait --wait-timeout 300 >>"$LOG" 2>&1; then
    HEALTH="$(dc ps --format json | python3 -c '
import json, sys
rows = [json.loads(l) for l in sys.stdin if l.strip()]
ok = sorted(r["Service"] for r in rows if r.get("Health") == "healthy")
print(str(len(ok)) + "/" + str(len(rows)) + " healthy: " + ",".join(ok))')"
    case "$HEALTH" in 8/8*) record PASS "8 serviços healthy" "$HEALTH" ;; *) record FAIL "8 serviços healthy" "$HEALTH" ;; esac
  else
    record FAIL "8 serviços healthy" "up --wait falhou (ver $LOG)"
  fi
else
  record FAIL "8 serviços healthy" "build falhou (ver $LOG)"
fi

# 3. migrate + seed (parâmetros globais e admin; demo não roda em production)
check "migrate + seed" "migrate --force e db:seed --force (admin $ADMIN_USER + 4 parâmetros globais)" \
  sh -c "docker compose -p $PROJECT -f $CLONE/docker-compose.yml --env-file $CLONE/.env --project-directory $CLONE exec -T app php artisan migrate --force && docker compose -p $PROJECT -f $CLONE/docker-compose.yml --env-file $CLONE/.env --project-directory $CLONE exec -T app php artisan db:seed --force"

# 4. scheduler com batimento recente (a tarefa roda a cada minuto)
AGE=""
for _ in $(seq 1 18); do
  AGE="$(dc exec -T scheduler sh -c 'f=storage/framework/schedule-heartbeat; [ -f $f ] && echo $(( $(date +%s) - $(stat -c %Y $f) ))' 2>/dev/null | tr -d '\r')"
  [ -n "$AGE" ] && [ "$AGE" -lt 150 ] && break
  sleep 10
done
if [ -n "$AGE" ] && [ "$AGE" -lt 150 ]; then
  PRUNE="$(dc exec -T scheduler php artisan schedule:list 2>/dev/null | grep -c 'sanctum:prune-expired')"
  record PASS "scheduler com batimento recente" "batimento há ${AGE}s (< 150 s); prune-expired agendado: $PRUNE"
else
  record FAIL "scheduler com batimento recente" "sem batimento em 3 min"
fi

# 5. pgvector instalado
VEC="$(dc exec -T db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "select extversion from pg_extension where extname = '"'"'vector'"'"'"' 2>/dev/null | tr -d '\r')"
[ -n "$VEC" ] && record PASS "pgvector" "extensão vector $VEC" || record FAIL "pgvector" "extensão vector ausente"

# 6. só o nginx publica porta (config do compose base e containers em execução)
PUBLISHED="$(docker ps --filter "label=com.docker.compose.project=$PROJECT" --format '{{.Label "com.docker.compose.service"}} {{.Ports}}' | awk '$2 ~ /->/ {print $1}' | sort | tr '\n' ' ' | sed 's/ $//')"
CONFIG_PUB="$(dc config --format json 2>/dev/null | python3 -c 'import json,sys; s=json.load(sys.stdin)["services"]; print(" ".join(sorted(n for n,v in s.items() if v.get("ports"))))')"
if [ "$PUBLISHED" = nginx ] && [ "$CONFIG_PUB" = nginx ]; then
  record PASS "só o nginx publica porta" "config: $CONFIG_PUB · em execução: $PUBLISHED"
else
  record FAIL "só o nginx publica porta" "config: [$CONFIG_PUB] · em execução: [$PUBLISHED]"
fi

# 7. health da API pelo nginx
H="$(curl -s "$API/health")"
if [ "$(echo "$H" | json 'd["data"]["app"]+"/"+d["data"]["db"]+"/"+d["data"]["redis"]' 2>/dev/null)" = "up/up/up" ]; then
  record PASS "health da API (nginx)" "app/db/redis up"
else
  record FAIL "health da API (nginx)" "$H"
fi

# 8. login por username
LOGIN="$(curl -s "${J[@]}" -X POST "$API/auth/login" -d "{\"username\":\"  ${ADMIN_USER^^} \",\"password\":\"$ADMIN_PASS\",\"device_name\":\"smoke\"}")"
TOKEN="$(echo "$LOGIN" | json 'd["data"]["token"]' 2>/dev/null)"
if [ -n "$TOKEN" ]; then
  record PASS "login por username" "$ADMIN_USER (caixa mista e espaços) → token Bearer"
else
  record FAIL "login por username" "$(echo "$LOGIN" | head -c 200)"
fi
AUTH=(-H "Authorization: Bearer $TOKEN")
post() { curl -s "${J[@]}" "${AUTH[@]}" -X POST "$API/$1" -d "$2"; }

# 9. cria filial, centro de custo, família e equipamento
BR="$(post branches '{"code":"SMK-01","name":"Filial smoke","type":"filial"}' | json 'd["data"]["id"]' 2>/dev/null)"
CC="$(post cost-centers "{\"code\":\"SMK-CC\",\"name\":\"CC smoke\",\"branch_id\":${BR:-0}}" | json 'd["data"]["id"]' 2>/dev/null)"
FAM="$(post equipment-families '{"code":"SMK-FAM","name":"Família smoke","category":"truck","criticality":"high"}' | json 'd["data"]["id"]' 2>/dev/null)"
EQR="$(post equipments "{\"code\":\"SMK-EQ01\",\"name\":\"Caminhão smoke\",\"family_id\":${FAM:-0},\"branch_id\":${BR:-0},\"cost_center_id\":${CC:-0},\"plate\":\"SMK0001\",\"odometer_km\":1234.5}")"
EQ="$(echo "$EQR" | json 'd["data"]["id"]' 2>/dev/null)"
if [ -n "$EQ" ]; then
  record PASS "cria família + equipamento" "filial $BR, centro de custo $CC, família $FAM, equipamento $EQ ($(echo "$EQR" | json 'd["data"]["plate"]+", criticidade "+d["data"]["criticality"]+" ("+d["data"]["criticality_source"]+")"'))"
else
  record FAIL "cria família + equipamento" "$(echo "$EQR" | head -c 200)"
fi

# 10. audit-logs com os eventos
AUD="$(curl -s "${J[@]}" "${AUTH[@]}" "$API/audit-logs?per_page=50" | json '" ".join(sorted({i["action"]+":"+(i["auditable"]["type"] if i["auditable"] else "-") for i in d["data"]}))' 2>/dev/null)"
MISSING=""
for ev in login_succeeded:user created:branch created:cost_center created:equipment_family created:equipment; do
  case " $AUD " in *" $ev "*) ;; *) MISSING="$MISSING $ev" ;; esac
done
[ -z "$MISSING" ] && record PASS "audit-logs com os eventos" "login_succeeded + created de filial, centro de custo, família e equipamento" \
  || record FAIL "audit-logs com os eventos" "faltando:$MISSING"

# 11. audit:verify
VERIFY="$(dc exec -T app php artisan audit:verify 2>&1 | tr -d '\r' | tail -n 1)"
if dc exec -T app php artisan audit:verify >/dev/null 2>&1; then
  record PASS "audit:verify" "exit 0 · $VERIFY"
else
  record FAIL "audit:verify" "$VERIFY"
fi

# 12. handshake Socket.io pela porta pública do nginx (cliente na imagem de teste do node)
if docker build -q --target test -t "$PROJECT/node-realtime:test" "$CLONE/services/node-realtime" >>"$LOG" 2>&1; then
  ws() { docker run --rm --network host -e WS_URL="ws://127.0.0.1:$PORT" ${1:+-e TOKEN="$1"} -v "$CLONE/services/node-realtime/scripts:/srv/scripts:ro" "$PROJECT/node-realtime:test" node scripts/handshake-check.mjs 2>/dev/null; }
  OKWS="$(ws "$TOKEN" | json 'd["result"]+" "+",".join(d.get("rooms",[]))' 2>/dev/null)"
  NOWS="$(ws "" | json 'd["result"]+" "+d.get("message","")' 2>/dev/null)"
  BADWS="$(ws "999|invalido" | json 'd["result"]+" "+d.get("message","")' 2>/dev/null)"
  if [ "${OKWS%% *}" = ready ] && [ "$NOWS" = "error unauthorized" ] && [ "$BADWS" = "error unauthorized" ]; then
    record PASS "Socket.io pelo nginx" "token válido → session:ready ($OKWS); sem token e inválido → unauthorized"
  else
    record FAIL "Socket.io pelo nginx" "válido: $OKWS · sem token: $NOWS · inválido: $BADWS"
  fi
else
  record FAIL "Socket.io pelo nginx" "build da imagem de teste do node falhou"
fi

# 13. python e node /health (internos, sem porta no host)
PY="$(dc exec -T python python -c "import urllib.request;print(urllib.request.urlopen('http://127.0.0.1:8000/health').read().decode())" 2>/dev/null | tr -d '\r')"
ND="$(dc exec -T node node -e "fetch('http://127.0.0.1:'+(process.env.NODE_PORT||3000)+'/health').then(r=>r.text()).then(t=>console.log(t))" 2>/dev/null | tr -d '\r')"
case "$PY" in *'"status":"ok"'*|*'"status": "ok"'*) record PASS "python /health" "$PY" ;; *) record FAIL "python /health" "$PY" ;; esac
case "$ND" in *'"status":"ok"'*) record PASS "node /health" "$ND" ;; *) record FAIL "node /health" "$ND" ;; esac

# 14. APP_DEBUG=false não vaza stack
ERR="$(curl -s "${J[@]}" "$API/nao-existe")"
if echo "$ERR" | grep -q '"status":"error"' && ! echo "$ERR" | grep -qiE 'trace|exception|/var/www|\.php'; then
  record PASS "erro sem vazar stack" "404 no envelope, sem trace/exception/caminho: $(echo "$ERR" | head -c 120)"
else
  record FAIL "erro sem vazar stack" "$(echo "$ERR" | head -c 200)"
fi

# 15. containers de aplicação não-root
ROOTS=""
for svc in app worker scheduler python node; do
  uid="$(dc exec -T "$svc" id -u 2>/dev/null | tr -d '\r')"
  [ "$uid" = 0 ] && ROOTS="$ROOTS $svc"
  USERS="${USERS:-}$svc=$uid "
done
[ -z "$ROOTS" ] && record PASS "aplicação não-root" "uid: ${USERS% }" || record FAIL "aplicação não-root" "como root:$ROOTS"

echo
echo "| # | Verificação | Resultado | Detalhe |"
echo "|---|---|---|---|"
i=0
for row in "${RESULTS[@]}"; do
  i=$((i + 1))
  IFS='|' read -r st name detail <<<"$row"
  printf '| %d | %s | %s | %s |\n' "$i" "$name" "$st" "$detail"
done
echo
if [ "$FAILED" -eq 0 ]; then
  echo "SMOKE PASS: ${#RESULTS[@]}/${#RESULTS[@]} verificações (commit $HEAD_REF)."
  exit 0
fi
echo "SMOKE FAIL: $FAILED de ${#RESULTS[@]} verificações falharam (commit $HEAD_REF). Log: $LOG (mantido em SMOKE_KEEP=1)."
exit 1
