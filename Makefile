.PHONY: help install start stop restart build logs shell test analyze fix-cs check-cs clear-cache

.DEFAULT_GOAL := help

help: ## Affiche cette aide
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

# Docker
build: ## Construit les images Docker
	docker-compose build --pull

start: ## Démarre les conteneurs
	docker-compose up -d

stop: ## Arrête les conteneurs
	docker-compose down

restart: ## Redémarre les conteneurs
	$(MAKE) stop
	$(MAKE) start

logs: ## Affiche les logs des conteneurs
	docker-compose logs -f

shell: ## Ouvre un shell dans le conteneur PHP
	docker-compose exec php bash

# Composer
install: ## Installe les dépendances Composer
	docker-compose exec php composer install

update: ## Met à jour les dépendances Composer
	docker-compose exec php composer update

require: ## Installe un package (usage: make require package=vendor/package)
	docker-compose exec php composer require $(package)

# Symfony
clear-cache: ## Vide le cache Symfony
	docker-compose exec php php bin/console cache:clear

# Tests
test: ## Lance les tests PHPUnit
	docker-compose exec php php bin/phpunit

test-coverage: ## Lance les tests avec couverture de code
	docker-compose exec php php bin/phpunit --coverage-html var/coverage

# Qualité de code
analyze: ## Analyse le code avec PHPStan
	docker-compose exec php vendor/bin/phpstan analyse

fix-cs: ## Corrige le code avec PHP-CS-Fixer
	docker-compose exec php vendor/bin/php-cs-fixer fix

check-cs: ## Vérifie le code avec PHP-CS-Fixer (dry-run)
	docker-compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff

# Logs
logs-sales: ## Affiche les logs d'import des ventes
	docker-compose exec php tail -f var/log/sales_import.log | jq .

logs-symfony: ## Affiche les logs Symfony
	docker-compose exec php tail -f var/log/dev.log

# Utilitaires
permissions: ## Corrige les permissions des fichiers
	docker-compose exec php chown -R www-data:www-data var/
	docker-compose exec php chmod -R 775 var/

init: build start install permissions ## Initialisation complète du projet

# Import test
test-import: ## Teste l'import avec un fichier CSV (usage: make test-import file=path/to/file.csv)
	curl -X POST http://localhost:8000/api/salesforce/import-sales \
		-F "file=@$(file)"
