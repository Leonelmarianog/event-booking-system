# Compose builds the image with the UID and GID of the host user. Bash does not export
# these variables, so the Makefile sets and exports them for each command.
UID := $(shell id -u)
GID := $(shell id -g)
export UID GID

COMPOSE := docker compose
APP := $(COMPOSE) exec app

.DEFAULT_GOAL := help

.PHONY: help setup up down test lint fresh

help: ## Show the targets
	@grep -E '^[a-z]+:.*## ' $(MAKEFILE_LIST) | awk -F ':.*## ' '{printf "  %-8s %s\n", $$1, $$2}'

setup: ## First start after a clone: install, start and migrate
	test -f .env || cp .env.example .env
	$(COMPOSE) build
	$(COMPOSE) run --rm app composer install
	grep -q '^APP_KEY=base64:' .env || $(COMPOSE) run --rm app php artisan key:generate
	$(MAKE) up
	$(APP) php artisan migrate --force

up: ## Build the image if necessary and start the stack
	$(COMPOSE) up -d --build --remove-orphans

down: ## Stop the stack (the data stays in the volumes)
	$(COMPOSE) down

test: ## Run the test suite
	$(APP) php artisan test

lint: ## Run the static checks of CI (no file changes)
	$(APP) composer lint:check
	$(APP) composer types:check
	$(APP) npm run check
	$(APP) npm run types:check

fresh: ## Delete all local data, then migrate and seed again
	$(APP) php artisan migrate:fresh --seed --force
