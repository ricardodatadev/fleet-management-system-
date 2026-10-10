# Alvos do projeto; os que ainda nao existem chegam nas tarefas indicadas e falham com mensagem clara.
# Sem nome do sistema aqui: o slug (projeto do compose, prefixo dos volumes) vem do .env.
.PHONY: up down init migrate seed test test-db test-python test-node openapi openapi-lint brand lint ci vendor-reset

APP_SLUG := $(shell sed -n 's/^APP_SLUG=//p' .env 2>/dev/null | tail -n 1 | tr -d "\"'")
# Prefixo das imagens de teste (python/node); sem APP_SLUG no .env, usa um nome neutro.
IMAGE_PREFIX := $(or $(APP_SLUG),local)

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
	docker compose exec -T app php artisan migrate --force

# Parametros globais + admin (todos os ambientes) + demonstracao (so local/testing). Idempotente.
seed:
	docker compose exec -T app php artisan db:seed --force

# Cria o banco <POSTGRES_DB>_test (idempotente) — necessário em volumes criados antes da F1-06.
test-db:
	docker compose exec -T db sh /docker-entrypoint-initdb.d/02-create-test-db.sh

# Pest com cobertura (pcov, so na imagem dev do override): falha abaixo de 80% em app/.
test: test-db
	docker compose exec -T app vendor/bin/pest --coverage --min=80

# Suites dos servicos (alvo test de cada Dockerfile; nada de python/node no host).
test-python:
	docker build -q --target test -t $(IMAGE_PREFIX)/python-ai:test services/python-ai
	docker run --rm $(IMAGE_PREFIX)/python-ai:test

test-node:
	docker build -q --target test -t $(IMAGE_PREFIX)/node-realtime:test services/node-realtime
	docker run --rm $(IMAGE_PREFIX)/node-realtime:test

# Gera docs/api/openapi.json (composer openapi no container app; docs/ é montado no override).
openapi:
	docker compose exec -T app composer openapi

# Lint da OpenAPI com Redocly em container node efêmero (nada de node no host).
openapi-lint:
	docker run --rm -v "$(CURDIR)":/spec -w /spec node:24.21.0-alpine npx --yes @redocly/cli@2.60.0 lint docs/api/openapi.json

# Guarda de marca: nome/slug so na fonte unica (.env.example) e na allowlist (scripts/brand-allowlist.txt).
brand:
	bash scripts/check-brand.sh

# Guarda de marca + teste da guarda + teste do compose + Pint + Larastan (nivel 5, sem baseline).
lint: brand
	bash scripts/check-brand.test.sh
	bash scripts/compose.test.sh
	docker compose exec -T app vendor/bin/pint --test
	docker compose exec -T app vendor/bin/phpstan analyse --memory-limit=1G --no-progress

# Gate local: lint + Pest com cobertura + pytest + Vitest do node.
ci: lint test test-python test-node

# Apos mudar composer.json/lock: rebuild e troca o volume do vendor (<projeto>_app_vendor) pelo da imagem nova.
vendor-reset:
	@test -n "$(APP_SLUG)" || { echo "ERRO: defina APP_SLUG no .env." >&2; exit 1; }
	docker compose build app
	docker compose down
	docker volume rm $(APP_SLUG)_app_vendor

