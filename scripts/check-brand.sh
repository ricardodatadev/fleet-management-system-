#!/usr/bin/env bash
# Guarda de marca (F1-33). O nome do sistema só pode aparecer na fonte única (.env.example) e nos
# arquivos de scripts/brand-allowlist.txt. Os valores são lidos do .env.example, nunca repetidos aqui.
#
# Falha (exit 1) se, num arquivo versionado fora da allowlist, aparecer:
#   - APP_NAME      palavra inteira, diferenciando caixa;
#   - APP_FULL_NAME texto exato;
#   - APP_SLUG      palavra inteira, sem diferenciar caixa (ex.: <slug>, <slug>_, <slug>-, <slug>/, <slug>.);
#   - o nome antigo (qualquer caixa), em qualquer arquivo, exceto a entrada histórica D17 do ADR-0001.
#
# Uso: bash scripts/check-brand.sh            # todos os arquivos versionados
#      bash scripts/check-brand.sh <arq>...   # só os arquivos indicados (usado por check-brand.test.sh)
# Trocar o nome: docs/renaming.md.
set -euo pipefail
export LC_ALL=C

root="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
cd "$root"

env_file=".env.example"
allowlist_file="scripts/brand-allowlist.txt"
history_file="docs/adr/0001-desvios-spec.md"
history_entry='^[0-9]+:\| D17 \|'   # saída do grep -n: "<linha>:| D17 | ..."
# Nome antigo montado sem o literal, para a própria guarda não conter a marca antiga.
old_name='sig''of'

read_env() {
    local value
    value="$(sed -n "s/^$1=//p" "$env_file" | tail -n 1)"
    value="${value%\"}"; value="${value#\"}"
    value="${value%\'}"; value="${value#\'}"
    [ -n "$value" ] || { echo "check-brand: $1 vazio ou ausente em $env_file" >&2; exit 2; }
    printf '%s' "$value"
}

name="$(read_env APP_NAME)"
full_name="$(read_env APP_FULL_NAME)"
slug="$(read_env APP_SLUG)"

# Escapa o valor para uso em ERE.
ere_escape() { printf '%s' "$1" | sed 's/[][\.*^$+?(){}|/]/\\&/g'; }
boundary_ere() { printf '(^|[^[:alnum:]])%s([^[:alnum:]]|$)' "$(ere_escape "$1")"; }

declare -A allowed=()
while IFS= read -r line; do
    line="${line%%#*}"
    line="$(printf '%s' "$line" | sed 's/[[:space:]]*$//; s/^[[:space:]]*//')"
    [ -n "$line" ] && allowed["$line"]=1
done < "$allowlist_file"

if [ "$#" -gt 0 ]; then
    files=("$@")
else
    mapfile -t files < <(git ls-files)
fi

failures=0
report() {
    # $1 = motivo; stdin = linhas "arquivo:linha:conteúdo"
    local hit
    while IFS= read -r hit; do
        [ -n "$hit" ] || continue
        echo "check-brand: $1: $hit" >&2
        failures=$((failures + 1))
    done
}

for file in "${files[@]}"; do
    [ -f "$file" ] || continue

    if [ "$file" = "$history_file" ]; then
        report "nome antigo" < <(grep -nIi -- "$old_name" "$file" | grep -Ev -- "$history_entry" | sed "s|^|$file:|" || true)
    else
        report "nome antigo" < <(grep -nHIi -- "$old_name" "$file" || true)
    fi

    [ -n "${allowed[$file]:-}" ] && continue

    report "APP_NAME fora da allowlist" < <(grep -nHIw -F -- "$name" "$file" || true)
    report "APP_FULL_NAME fora da allowlist" < <(grep -nHI -F -- "$full_name" "$file" || true)
    report "APP_SLUG fora da allowlist" < <(grep -nHIi -E -- "$(boundary_ere "$slug")" "$file" || true)
done

# Toda entrada da allowlist precisa existir (evita allowlist velha depois de mover arquivos).
for path in "${!allowed[@]}"; do
    if [ ! -e "$path" ]; then
        echo "check-brand: entrada inexistente na allowlist: $path" >&2
        failures=$((failures + 1))
    fi
done

if [ "$failures" -gt 0 ]; then
    echo "check-brand: FALHOU ($failures ocorrência(s)). Ver docs/renaming.md." >&2
    exit 1
fi
echo "check-brand: ok (${#files[@]} arquivo(s), ${#allowed[@]} na allowlist)"
