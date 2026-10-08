# SIGOF-M — alvos reais chegam nas tarefas indicadas; ate la falham com mensagem clara.
.PHONY: up down init migrate seed test test-db openapi openapi-lint lint ci

up:
	docker compose up -d --build --wait

down:
	docker compose down

init:
	@# key:generate: grava APP_KEY no .env do host (o container não tem .env) e recria app/worker; depois migra.
	@grep -q '^APP_KEY=base64:' .env || { \
		key=$$(docker compose run --rm --no-deps -T app php artisan key:generate --show) && \
		sed -i "s|^APP_KEY=.*|APP_KEY=$$key|" .env && echo "APP_KEY gerada em .env"; }
	docker compose up -d --wait
	$(MAKE) migrate

migrate:
	docker compose exec app php artisan migrate --force

seed:
	@echo "ERRO: 'make seed' ainda nao implementado (disponivel a partir de F1-17 (seeders))." >&2; exit 1

# Cria o banco sigof_test (idempotente) — necessário em volumes criados antes da F1-06.
test-db:
	docker compose exec -T db sh /docker-entrypoint-initdb.d/02-create-test-db.sh

test: test-db
	docker compose exec -T app vendor/bin/pest

# Gera docs/api/openapi.json (composer openapi no container app; docs/ é montado no override).
openapi:
	docker compose exec -T app composer openapi

# Lint da OpenAPI com Redocly em container node efêmero (nada de node no host).
openapi-lint:
	docker run --rm -v "$(CURDIR)":/spec -w /spec node:24.21.0-alpine npx --yes @redocly/cli@2.60.0 lint docs/api/openapi.json

lint:
	@echo "ERRO: 'make lint' ainda nao implementado (disponivel a partir de F1-19 (Pint/Larastan))." >&2; exit 1

ci:
	@echo "ERRO: 'make ci' ainda nao implementado (disponivel a partir de F1-19 (gate local))." >&2; exit 1

