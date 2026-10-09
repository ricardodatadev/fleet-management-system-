#!/usr/bin/env bash
# Teste da guarda de marca (scripts/check-brand.sh): casos injetados fora da allowlist devem
# falhar (exit != 0) e arquivos da allowlist devem passar. Os valores vêm do .env.example.
set -euo pipefail

root="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
cd "$root"
guard="scripts/check-brand.sh"

read_env() { sed -n "s/^$1=//p" .env.example | tail -n 1 | sed 's/^"\(.*\)"$/\1/'; }
slug="$(read_env APP_SLUG)"
name="$(read_env APP_NAME)"
full_name="$(read_env APP_FULL_NAME)"
old_name='sig''of'

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

failed=0
expect() {
    # $1 = exit esperado ("0" ou "!0"); $2 = descrição; demais = argumentos da guarda
    local want="$1" desc="$2" code=0
    shift 2
    bash "$guard" "$@" >/dev/null 2>&1 || code=$?
    if { [ "$want" = "0" ] && [ "$code" -eq 0 ]; } || { [ "$want" = "!0" ] && [ "$code" -ne 0 ]; }; then
        echo "ok   - $desc (exit $code)"
    else
        echo "FAIL - $desc (exit $code, esperado $want)"
        failed=1
    fi
}

printf 'volume: %s_app_vendor\n' "$slug" > "$tmp/slug.txt"
printf 'imagem %s/app:local\n' "$(printf '%s' "$slug" | tr '[:lower:]' '[:upper:]')" > "$tmp/slug-caixa.txt"
printf 'Bem-vindo ao %s.\n' "$name" > "$tmp/nome.txt"
printf '%s\n' "$full_name" > "$tmp/nome-completo.txt"
printf 'antigo: %s_test\n' "$old_name" > "$tmp/antigo.txt"
printf 'x%sy %s1 sem marca\n' "$slug" "$slug" > "$tmp/limpo.txt"

expect '!0' "slug injetado fora da allowlist" "$tmp/slug.txt"
expect '!0' "slug em outra caixa fora da allowlist" "$tmp/slug-caixa.txt"
expect '!0' "nome curto fora da allowlist" "$tmp/nome.txt"
expect '!0' "nome completo fora da allowlist" "$tmp/nome-completo.txt"
expect '!0' "nome antigo" "$tmp/antigo.txt"
expect '0' "slug dentro de outra palavra não conta" "$tmp/limpo.txt"
expect '0' "arquivo da allowlist com a marca" .env.example
expect '0' "repositório atual (arquivos versionados)"

exit "$failed"
