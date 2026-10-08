#!/bin/sh
# Não executa migrations automaticamente (use `make init` / `make migrate`).
set -e
exec "$@"
