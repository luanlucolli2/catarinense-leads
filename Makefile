.PHONY: up

up:
	@if [ ! -f backend/.env ]; then cp backend/.env.example backend/.env; fi
	@if [ ! -x backend/vendor/bin/sail ]; then \
		docker run --rm --user "$$(id -u):$$(id -g)" -v "$(CURDIR)/backend:/app" -w /app \
			laravelsail/php84-composer:latest composer install --ignore-platform-req=ext-gd; \
	fi
	cd backend && ./vendor/bin/sail up -d --build && ./vendor/bin/sail artisan migrate --force && ./vendor/bin/sail artisan optimize:clear && ./vendor/bin/sail artisan storage:link && ./vendor/bin/sail artisan queue:restart
