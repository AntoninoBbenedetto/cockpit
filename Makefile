COMPOSE = docker compose

.PHONY: up down shell test lint

up:
	@test -f .env || cp .env.example .env
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

shell:
	$(COMPOSE) exec app sh

test:
	$(COMPOSE) exec app ./vendor/bin/pest

lint:
	$(COMPOSE) exec app ./vendor/bin/pint --test
	$(COMPOSE) exec app ./vendor/bin/phpstan analyse --memory-limit=1G
