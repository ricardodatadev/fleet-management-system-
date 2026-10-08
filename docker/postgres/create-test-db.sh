#!/bin/sh
# Cria (idempotente) o banco de testes "<POSTGRES_DB>_test" com a extensão vector.
# Usado de duas formas:
#   - volume novo: executado pelo entrypoint do postgres (/docker-entrypoint-initdb.d/02-create-test-db.sh)
#   - volume existente: `make test-db` (docker compose exec db sh /docker-entrypoint-initdb.d/02-create-test-db.sh)
# O nome é fixo (sigof_test) porque o phpunit.xml o força; a suíte aborta se o banco não terminar em _test.
set -eu

TEST_DB="sigof_test"
PSQL="psql -v ON_ERROR_STOP=1 -U ${POSTGRES_USER}"

if [ "$($PSQL -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='${TEST_DB}'")" != "1" ]; then
    $PSQL -d postgres -c "CREATE DATABASE ${TEST_DB}"
    echo "banco ${TEST_DB} criado"
else
    echo "banco ${TEST_DB} já existe"
fi
$PSQL -d "${TEST_DB}" -c "CREATE EXTENSION IF NOT EXISTS vector"
