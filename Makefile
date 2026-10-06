# Both stacks run the same image. Production is the default stack, development
# adds the source mount and the worker reload.
DEV_COMPOSE = docker compose -f compose.yaml -f compose.dev.yaml

.PHONY: up down dev dev-down logs logs-worker

up: ## Build and start the production stack
	docker compose up -d --build

down: ## Stop the production stack
	docker compose down

dev: ## Build and start the development stack
	$(DEV_COMPOSE) up -d --build

dev-down: ## Stop the development stack
	$(DEV_COMPOSE) down

logs: ## Follow the application logs
	docker compose logs -f app

logs-worker: ## Follow the Messenger worker logs
	docker compose logs -f worker
