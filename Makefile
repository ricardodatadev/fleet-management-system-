# SIGOF-M — alvos reais chegam nas tarefas indicadas; ate la falham com mensagem clara.
.PHONY: up down init migrate seed test lint ci

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

test:
	docker compose exec -T app vendor/bin/pest

lint:
	@echo "ERRO: 'make lint' ainda nao implementado (disponivel a partir de F1-19 (Pint/Larastan))." >&2; exit 1

ci:
	@echo "ERRO: 'make ci' ainda nao implementado (disponivel a partir de F1-19 (gate local))." >&2; exit 1

