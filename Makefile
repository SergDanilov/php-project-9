PORT ?= 8000

start:
	php -S 0.0.0.0:$(PORT) -t public public/index.php

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
	composer exec --verbose phpcs -- --standard=PSR12 public src templates

test:
	composer exec --verbose phpunit tests