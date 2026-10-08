# SIGOF-M — alvos reais chegam nas tarefas indicadas; ate la falham com mensagem clara.
.PHONY: up down init migrate seed test lint ci

up:
	@echo "ERRO: 'make up' ainda nao implementado (disponivel a partir de F1-02/F1-03 (docker compose))." >&2; exit 1

down:
	@echo "ERRO: 'make down' ainda nao implementado (disponivel a partir de F1-02 (docker compose))." >&2; exit 1

init:
	@echo "ERRO: 'make init' ainda nao implementado (disponivel a partir de F1-03 (key:generate + migrate))." >&2; exit 1

migrate:
	@echo "ERRO: 'make migrate' ainda nao implementado (disponivel a partir de F1-03/F1-09 (Laravel))." >&2; exit 1

seed:
	@echo "ERRO: 'make seed' ainda nao implementado (disponivel a partir de F1-17 (seeders))." >&2; exit 1

test:
	@echo "ERRO: 'make test' ainda nao implementado (disponivel a partir de F1-19 (suites backend/python/node))." >&2; exit 1

lint:
	@echo "ERRO: 'make lint' ainda nao implementado (disponivel a partir de F1-19 (Pint/Larastan))." >&2; exit 1

ci:
	@echo "ERRO: 'make ci' ainda nao implementado (disponivel a partir de F1-19 (gate local))." >&2; exit 1

