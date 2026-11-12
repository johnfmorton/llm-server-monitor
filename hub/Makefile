.PHONY: dev up install migrate fresh test

# Open the site and tail the app log. The queue worker and scheduler run as ddev daemons
# (.ddev/config.workers.yaml), so they're already going; Ctrl-C only stops the log tail.
# No Vite here: the hub serves a hand-written public/css/app.css.
dev: up
	ddev launch
	ddev artisan pail

up:
	@ddev describe 2>/dev/null | grep -q OK || ddev start

# First-time setup.
install: up
	ddev exec 'test -f .env || cp .env.example .env'
	ddev composer install
	ddev artisan key:generate
	ddev artisan migrate

migrate: up
	ddev artisan migrate

# Wipe and reseed the database.
fresh: up
	ddev artisan migrate:fresh --seed

test: up
	ddev artisan test
