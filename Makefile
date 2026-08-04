THIS_FILE := $(lastword $(MAKEFILE_LIST))

.PHONY: help build bash install install-dev up restart down run npm-build restart-queue npm-install

help: ## Display this help message with descriptions of all available Makefile targets.
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(THIS_FILE) | \
    awk 'BEGIN {FS = ":.*?## "}; {printf "\033[33m%-20s\033[0m %s\n", $$1, $$2}'

bash-php: ## Start a Bash shell session inside the 'marketing-php' container.
	docker compose run --rm marketing-php bash

bash-composer: ## Start a Bash shell session inside the 'marketing-composer' container.
	docker compose run --rm marketing-composer bash

install: ## Install production PHP dependencies and prepare .env
	@if [ ! -f src/.env ]; then \
      	echo "Creating .env from .env.example"; \
      	cp src/.env.example src/.env; \
    fi
	docker compose run --rm marketing-composer sh -c "composer install --no-cache"
	docker compose run --rm marketing-php sh -c "php artisan key:generate"
	make up
	make migrate
	make tests

migrate: ## Run migrate command in the container.
	docker compose run --rm marketing-php sh -c "php artisan migrate --force"

up: ## Start all Docker services in detached mode, forcing recreation of containers.
	docker compose up -d

down: ## Stop and remove Docker containers, networks, and orphans.
	docker compose down

tests:
	docker compose exec marketing-php sh -c "./vendor/bin/phpunit --testdox"
