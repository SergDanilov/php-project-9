PORT ?= 8000

start:
	docker compose up -d

stop:
	docker compose down

setup:
	composer install

compose-bash:
	docker compose run web bash

compose-setup: compose-build
	docker compose run web make setup

compose-build:
	docker compose build

lint:
	composer exec --verbose phpcs -- --standard=PSR12 public templates

test:
	composer exec --verbose phpunit tests