#!/usr/bin/env bash
# Teste do compose base (F1-19): 8 serviços, só o nginx publica porta, e o scheduler existe com
# schedule:work, sem porta, sem usuário root e com healthcheck. Usa `docker compose config` (não sobe nada).
set -euo pipefail
root="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
cd "$root"

# O compose exige as variáveis obrigatórias: valores de mentira só para renderizar a config.
export POSTGRES_DB=x POSTGRES_USER=x POSTGRES_PASSWORD=x REDIS_PASSWORD=x
docker compose -f docker-compose.yml --env-file /dev/null config --format json | python3 -c '
import json, sys
services = json.load(sys.stdin)["services"]
fail = []
expected = {"nginx", "app", "worker", "scheduler", "db", "redis", "python", "node"}
if set(services) != expected:
    fail.append(f"serviços: {sorted(services)} (esperado {sorted(expected)})")
published = sorted(n for n, s in services.items() if s.get("ports"))
if published != ["nginx"]:
    fail.append(f"publicam porta: {published} (esperado só nginx)")
sch = services.get("scheduler", {})
if sch.get("command") != ["php", "artisan", "schedule:work"]:
    fail.append("scheduler.command = " + json.dumps(sch.get("command")))
if sch.get("ports"):
    fail.append("scheduler publica porta")
if sch.get("user") in ("root", "0", "0:0"):
    fail.append("scheduler roda como root")
if "schedule-heartbeat" not in json.dumps(sch.get("healthcheck", {})):
    fail.append("scheduler sem healthcheck por batimento")
if sch.get("image") != services["app"].get("image"):
    fail.append("scheduler com imagem diferente do app")
if "schedule" in json.dumps(services["worker"].get("command")):
    fail.append("worker roda o schedule (deve rodar só o Horizon)")
for line in fail:
    print("compose.test: FALHOU:", line)
if fail:
    sys.exit(1)
print(f"compose.test: ok ({len(services)} serviços; só o nginx publica porta; scheduler com schedule:work, sem porta, não-root, healthcheck por batimento)")
'
