# SF API Import Salesforce

API Symfony pour l'import des ventes à partir d'un fichier CSV dans Salesforce via Bulk API 2.0.

## Prérequis

- Docker & Docker Compose (Docker Desktop doit être démarré)
- Make

## Installation

⚠️ **Important** : Assurez-vous que Docker Desktop est démarré avant de lancer les commandes.

```bash
# 1. Cloner le projet
git clone <repository-url>
cd sf-api-import-salesforce

# 2. Copier le fichier d'environnement
cp .env.example .env

# 3. Configurer les variables Salesforce dans .env
nano .env

# 4. Initialisation complète (build + start + install + permissions)
make init
```

## Commandes Make disponibles

### Docker
```bash
make docker-build    # Construit les images Docker
make docker-up       # Démarre les conteneurs
make docker-stop     # Arrête les conteneurs
make docker-restart  # Redémarre les conteneurs
make docker-logs     # Affiche les logs des conteneurs
make docker-shell    # Ouvre un shell dans le conteneur PHP
```

### Composer
```bash
make composer-install                         # Installe les dépendances
make composer-update                          # Met à jour les dépendances
make composer-require package=vendor/package  # Installe un package
```

### Symfony
```bash
make console-cc      # Vide le cache Symfony
```

### Tests
```bash
make test            # Lance les tests PHPUnit
make test-coverage   # Lance les tests avec couverture de code
```

### Qualité de code
```bash
make analyze         # Analyse le code avec PHPStan
make fix-cs          # Corrige le code avec PHP-CS-Fixer
make check-cs        # Vérifie le code avec PHP-CS-Fixer (dry-run)
```

### Logs
```bash
make logs-sales      # Affiche les logs d'import des ventes
make logs-symfony    # Affiche les logs Symfony
```

### Utilitaires
```bash
make permissions     # Corrige les permissions des fichiers
make init            # Initialisation complète du projet
make help            # Affiche toutes les commandes disponibles
```

### Import test
```bash
make test-import file=path/to/file.csv  # Teste l'import avec un fichier CSV
```

## Accès

- **API** : http://localhost:8000
- **Endpoint import** : POST http://localhost:8000/api/salesforce/import-sales

## Développement

```bash
# Démarrer l'environnement
make docker-up

# Installer les dépendances
make composer-install

# Ouvrir un shell dans le conteneur
make docker-shell

# Suivre les logs
make docker-logs
```

## Production

Pour la production, modifier `APP_ENV=prod` dans le fichier `.env` et reconstruire l'image.
