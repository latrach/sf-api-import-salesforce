# CLAUDE.md - SF API Import Salesforce

## Projet

API Symfony pour l'import des ventes des extensions de garanties (sales) partenaires dans Salesforce via la **Bulk API 2.0**.
L'orchestration est assurée par **VisualCron** (SFTP + appel HTTP uniquement, **pas de PowerShell**).

## Installation et démarrage rapide

### Prérequis
- Docker & Docker Compose
- Make

### Installation complète

```bash
# 1. Cloner le projet
git clone <repository-url>
cd sf-api-import-salesforce

# 2. Copier et configurer les variables d'environnement
cp .env.example .env
nano .env  # Configurer les credentials Salesforce

# 3. Initialisation complète (build + start + install + permissions)
make init

# 4. Vérifier que tout fonctionne
make logs
```

### Accès
- **API** : http://localhost:8000
- **Endpoint import** : POST http://localhost:8000/api/salesforce/import-sales

### Commandes de base

```bash
make docker-up              # Démarre l'environnement
make docker-down            # Arrête l'environnement
make docker-restart         # Redémarre l'environnement
make docker-logs                   # Affiche les logs en temps réel
make docker-shell                  # Ouvre un shell dans le conteneur PHP
```

## Architecture

```
[VisualCron]
  Task 1: SFTP Download → Récupération CSV partenaire
  Task 2: HTTP POST     → Appel API Symfony avec fichier CSV
  Task 3: HTTP GET      → Récupération logs (optionnel)
  Task 4: Email          → Notification basée sur réponse API

[API Symfony]
  Controller → Service Import → Validation → Transformation → Salesforce Bulk API 2.0
```

## Stack technique

- **Framework** : Symfony (PHP 8.2+)
- **Salesforce** : Bulk API 2.0 (v59.0) — objet `Opportunity`, opération `insert`
- **Orchestrateur** : VisualCron (SFTP + HTTP POST uniquement)
- **Logs** : Monolog JSON structuré, canal `sales_import`, rotation 90 jours
- **Auth API** : Aucune (API interne)
- **Auth Salesforce** : OAuth2 JWT Bearer Flow (avec clé privée RSA)

## Structure des services

```
src/
├── Controller/
│   └── Api/
│       └── SalesImportController.php        # POST /api/salesforce/import-sales
└── Service/
    ├── Salesforce/
    │   ├── SalesforceAuthService.php        # OAuth2 JWT Bearer Flow authentication
    │   ├── SalesforceBulkService.php        # Bulk API 2.0 (create/upload/close/poll/results)
    │   └── SalesforceQueryService.php       # SOQL queries
    └── Sales/
        ├── PartnerReconciliationService.php # Enrichissement AccountId via SOQL (Account = partenaire)
        ├── CsvParserService.php             # Parsing CSV partenaire
        ├── SalesImportService.php           # Service principal (orchestration)
        ├── SalesTransformerService.php      # Transformation données → format SF
        └── SalesValidatorService.php        # Validation métier (dates, email, prix)
```

## Format CSV partenaire

### Structure du fichier `sales_{date}.csv`

| Champ CSV                  | Description                              | Type     | Obligatoire |
|----------------------------|------------------------------------------|----------|-------------|
| `partner_name`             | Nom du partenaire (ex: Fnac, Darty)      | string   | ✓           |
| `customer_email`           | Email du client final                    | string   | ✓           |
| `product_name`             | Nom du produit                           | string   | ✓           |
| `warranty_code`            | Code de la garantie                      | string   | ✓           |
| `warranty_label`           | Libellé de la garantie/extension         | string   | ✓           |
| `warranty_start_date`      | Date de début de garantie                | date     | ✓           |
| `warranty_end_date`        | Date de fin de garantie                  | date     | ✓           |
| `product_purchase_price`   | Prix d'achat du produit (HT)             | decimal  | ✓           |
| `warranty_purchase_date`   | Date d'achat de la garantie              | date     | ✓           |
| `invoice_number`           | Numéro de facture                        | string   | ✓           |
| `purchase_date`            | Date d'achat                             | date     | ✓           |
| `customer_address_street`  | Rue du client                            | string   | ✓           |
| `customer_address_city`    | Ville du client                          | string   | ✓           |
| `customer_address_zipcode` | Code postal du client                    | string   | ✓           |
| `customer_address_country` | Pays du client                           | string   | ✓           |

### Exemple
```csv
partner_name,customer_email,product_name,warranty_code,warranty_label,warranty_start_date,warranty_end_date,product_purchase_price,warranty_purchase_date,invoice_number,purchase_date,customer_address_street,customer_address_city,customer_address_zipcode,customer_address_country
Fnac,jean.dupont@email.com,iPhone 15 Pro,EXT2Y,Extension Garantie 2 ans,2024-01-15,2026-01-15,1199.99,2024-01-15,INV-2024-001,2024-01-15,12 rue de la Paix,Paris,75002,France
Darty,marie.martin@email.com,MacBook Air M3,APPC3Y,AppleCare+ 3 ans,2024-02-01,2027-02-01,1499.00,2024-02-01,INV-2024-002,2024-02-01,45 avenue des Champs,Lyon,69001,France
```

### Formats et validations
- **Format dates** : `YYYY-MM-DD` (ISO 8601)
- **Format email** : Validation RFC 5322
- **Validation dates** : `warranty_start_date` < `warranty_end_date`
- **Validation dates** : `purchase_date` <= `warranty_purchase_date` <= `warranty_start_date`
- **warranty_code** : Alphanumerique, max 50 caractères

## Flux d'import (SalesImportService)

1. **Parsing** — Lecture du CSV partenaire avec validation de la structure
2. **Validation** — Règles métier (dates, prix, email, champs obligatoires), export CSV des erreurs de validation
3. **Réconciliation partenaires** — Requête SOQL pour mapper `partner_name` → `Account.Id` Salesforce (Account = partenaire, pas client final)
4. **Transformation** — Conversion des données validées au format Salesforce (Opportunity + champs custom + Contact client)
5. **Import Bulk API 2.0** — Create job → Upload CSV → Close job → Poll → Get results
6. **Gestion erreurs SF** — Export CSV des erreurs Salesforce (failedResults)
7. **Archivage** — Déplacement du fichier source vers `var/imports/sales/archive/YYYY-MM/`

## Endpoints API

| Méthode | Route                            | Description                          |
|---------|----------------------------------|--------------------------------------|
| POST    | `/api/salesforce/import-sales`   | Import d'un fichier CSV de garanties |

### Réponse succès (200)
```json
{
  "status": "success",
  "import_id": "20240115_abc123",
  "summary": {
    "total_lines": 1500,
    "validation": { "valid": 1480, "errors": 20 },
    "salesforce": { "job_id": "750xx...", "success": 1470, "errors": 10 },
    "duration_seconds": 45.2
  },
  "files": {
    "validation_errors": "VALIDATION_ERRORS_xxx.csv",
    "salesforce_errors": "SALESFORCE_ERRORS_xxx.csv"
  }
}
```

### Réponse erreur (500)
```json
{
  "status": "error",
  "import_id": "20240115_abc123",
  "error": "Message d'erreur",
  "duration_seconds": 2.1
}
```

## Mapping CSV → Salesforce Opportunity

| Champ CSV                  | Champ Salesforce              | Transformation                          |
|----------------------------|-------------------------------|-----------------------------------------|
| `partner_name`             | `AccountId`                   | Via réconciliation SOQL (Account = partenaire) |
| `customer_email`           | `Customer_Email__c`           | Email validé RFC 5322                   |
| `product_name`             | `Product_Name__c`             | Texte brut                              |
| `warranty_code`            | `Warranty_Code__c`            | Code unique de la garantie              |
| `warranty_label`           | `Name`                        | Nom de l'opportunité                    |
| `warranty_start_date`      | `Warranty_Start_Date__c`      | Date ISO → Salesforce Date              |
| `warranty_end_date`        | `Warranty_End_Date__c`        | Date ISO → Salesforce Date              |
| `product_purchase_price`   | `Product_Purchase_Price__c`   | Decimal (2 décimales)                   |
| `warranty_purchase_date`   | `CloseDate`                   | Date de l'opportunité                   |
| `invoice_number`           | `Invoice_Number__c`           | Texte brut                              |
| `purchase_date`            | `Purchase_Date__c`            | Date ISO → Salesforce Date              |
| `customer_address_*`       | `Shipping_Address__c`         | Concaténation adresse complète          |
| *Fixe*                     | `StageName`                   | `"Closed Won"` (vente finalisée)        |
| *Fixe*                     | `Type`                        | `"Warranty Extension"`                  |
| *Calculé*                  | `Amount`                      | `product_purchase_price` (si applicable)|

### Règles de transformation
- **AccountId** : Récupéré via SOQL `SELECT Id, Name FROM Account WHERE Name = :partner_name` (Account représente le partenaire : Fnac, Darty, etc.)
- **Customer_Email__c** : Email du client final (stocké sur l'opportunité)
- **Shipping_Address__c** : Format `{street}, {zipcode} {city}, {country}`
- **Name** : `"{warranty_label} - {customer_email} - {invoice_number}"`
- **Warranty_Code__c** : Code unique de la garantie (ex: EXT2Y, APPC3Y)
- **StageName** : Toujours `"Closed Won"` car ce sont des ventes déjà réalisées
- **Type** : `"Warranty Extension"` pour distinguer des autres opportunités

### Notes sur le modèle de données
- **Account** = Partenaire distributeur (Fnac, Darty, Boulanger, etc.)
- **Opportunity** = Vente de garantie avec infos client final (email, adresse)
- **Contact** = Non créé dans cette version (infos client stockées directement sur Opportunity via champs custom)

## Conventions de code

- **PHP 8.2+** : Attributs PHP 8 (`#[Route]`, `#[MapUploadedFile]`), types stricts, readonly properties
- **Injection de dépendances** : Constructor injection uniquement
- **Logging** : Logger dédié `salesLogger` (canal `sales_import`), toujours inclure `import_id` dans le contexte
- **Nommage import** : `import_id` = `date('YmdHis') . '_' . uniqid()`
- **Fichiers de travail** : `var/imports/sales/YYYY-MM-DD/`
- **Fichiers archivés** : `var/imports/sales/archive/YYYY-MM/`
- **Gestion erreurs** : Export CSV systématique des erreurs (validation + Salesforce)
- **Pas de PowerShell** : Toute la logique est dans Symfony, VisualCron fait uniquement SFTP + HTTP
- **Validation dates** : Vérifier cohérence `purchase_date` <= `warranty_purchase_date` <= `warranty_start_date` < `warranty_end_date`
- **Validation prix** : `product_purchase_price` > 0, max 2 décimales
- **Validation email** : Format RFC 5322 valide pour `customer_email`
- **Validation partenaire** : `partner_name` doit correspondre à un Account existant dans Salesforce

## Configuration

### Prérequis Salesforce - Connected App avec JWT Bearer Flow

#### 1. Générer une paire de clés RSA

```bash
# Générer la clé privée (à garder secrète)
openssl genrsa -out salesforce_private_key.pem 2048

# Générer la clé publique (à uploader dans Salesforce)
openssl req -new -x509 -key salesforce_private_key.pem -out salesforce_public_key.pem -days 365
```

#### 2. Créer une Connected App dans Salesforce

1. Dans Salesforce Setup, aller dans **App Manager**
2. Cliquer sur **New Connected App**
3. Remplir les informations de base (Name, Contact Email, etc.)
4. Cocher **Enable OAuth Settings**
5. Callback URL : `https://login.salesforce.com/services/oauth2/callback` (ou `https://test.salesforce.com/...` pour sandbox)
6. Cocher **Use digital signatures**
7. Uploader le fichier `salesforce_public_key.pem`
8. Sélectionner les OAuth Scopes nécessaires : `api`, `refresh_token`, `offline_access`
9. Sauvegarder et récupérer le **Consumer Key** (c'est votre `SALESFORCE_CLIENT_ID`)

#### 3. Autoriser l'utilisateur

1. Dans la Connected App, aller dans **Manage**
2. Cliquer sur **Edit Policies**
3. Dans **Permitted Users**, sélectionner **Admin approved users are pre-authorized**
4. Assigner l'utilisateur d'intégration à un **Permission Set** ou **Profile** qui a accès à la Connected App

### Variables d'environnement (.env)

```bash
# URL de l'instance Salesforce
SALESFORCE_INSTANCE_URL=https://yourinstance.salesforce.com

# Consumer Key de la Connected App
SALESFORCE_CLIENT_ID=your_consumer_key_from_connected_app

# Utilisateur Salesforce pour l'authentification JWT
SALESFORCE_USERNAME=integration@yourcompany.com

# Chemin vers la clé privée RSA (générée à l'étape 1)
SALESFORCE_PRIVATE_KEY_PATH=/path/to/salesforce_private_key.pem

# Audience URL pour JWT
# Production: https://login.salesforce.com
# Sandbox: https://test.salesforce.com
SALESFORCE_AUDIENCE_URL=https://login.salesforce.com
```

### Fichiers de configuration Symfony
- `config/packages/framework.yaml` — HTTP client scopé `salesforce.client` (timeout 300s)
- `config/packages/monolog.yaml` — Canal `sales_import`, rotating_file JSON, 90 jours
- `config/services.yaml` — Binding du client HTTP Salesforce avec JWT
- `config/bootstrap.php` — Chargement des variables d'environnement

## Salesforce Bulk API 2.0

- **Endpoint** : `/services/data/v59.0/jobs/ingest`
- **Content-Type upload** : `text/csv`
- **Line ending** : `LF`
- **Polling** : Toutes les 5 secondes, timeout 600 secondes
- **États terminaux** : `JobComplete`, `Failed`, `Aborted`
- **Résultats erreurs** : `GET /jobs/ingest/{jobId}/failedResults` (format CSV)

## VisualCron

- **Task 1** : SFTP Download — Trigger daily 5h00, fichier `sales_{date}.csv`
- **Task 2** : HTTP POST vers `/api/salesforce/import-sales` — multipart/form-data avec le CSV
- **Task 3** : Email succès — Basé sur `$.status == "success"` dans la réponse JSON
- **Task 4** : Email erreur — Basé sur échec ou `$.status == "error"`

## Environnement Docker

### Stack
- **PHP** : 8.2-fpm avec extensions (intl, zip, bcmath, soap, opcache)
- **Apache** : 2.4 avec mod_rewrite et proxy_fcgi
- **Composer** : 2.x

### Fichiers de configuration
- `Makefile` — Commandes pour gérer le projet
- `docker-compose.yml` — Services Docker (PHP-FPM + Apache)
- `docker/Dockerfile` — Image PHP personnalisée
- `docker/php/php.ini` — Configuration PHP (memory_limit=512M, upload_max_filesize=50M, max_execution_time=600s)
- `docker/apache/httpd.conf` — Configuration Apache
- `docker/apache/vhost.conf` — VirtualHost Symfony

### Ports
- **8000** : Apache (http://localhost:8000)

## Commandes Make disponibles

### Installation et démarrage (Docker)

| Commande | Description |
|----------|-------------|
| `make init` | **Initialisation complète du projet** (docker-build + docker-up + composer-install + permissions) |
| `make docker-build` | Construit/reconstruit les images Docker |
| `make docker-up` | Démarre les conteneurs Docker en arrière-plan |
| `make docker-stop` | Arrête tous les conteneurs |
| `make docker-restart` | Redémarre tous les conteneurs (stop + up) |
| `make docker-logs` | Affiche les logs de tous les conteneurs en temps réel (Ctrl+C pour quitter) |
| `make docker-shell` | Ouvre un shell bash dans le conteneur PHP (pour exécuter des commandes directement) |

### Gestion des dépendances (Composer)

| Commande | Description |
|----------|-------------|
| `make composer-install` | Installe les dépendances Composer définies dans composer.lock |
| `make composer-update` | Met à jour les dépendances Composer |
| `make composer-require package=vendor/package` | Installe un nouveau package Composer (ex: `make composer-require package=symfony/validator`) |

### Symfony

| Commande | Description |
|----------|-------------|
| `make console-cc` | Vide le cache Symfony (à faire après modification de config) |

### Tests et qualité de code

| Commande | Description |
|----------|-------------|
| `make test` | Lance tous les tests PHPUnit |
| `make test-coverage` | Lance les tests avec rapport de couverture HTML (généré dans var/coverage/) |
| `make analyze` | Analyse statique du code avec PHPStan (détecte erreurs potentielles) |
| `make fix-cs` | Corrige automatiquement le style de code avec PHP-CS-Fixer |
| `make check-cs` | Vérifie le style de code sans modifier (dry-run) |

### Logs et monitoring

| Commande | Description |
|----------|-------------|
| `make logs-sales` | Affiche les logs d'import des ventes en temps réel (format JSON avec jq) |
| `make logs-symfony` | Affiche les logs Symfony (var/log/dev.log) |

### Utilitaires

| Commande | Description |
|----------|-------------|
| `make permissions` | Corrige les permissions des répertoires var/ (à faire si erreurs d'écriture) |
| `make test-import file=chemin/vers/fichier.csv` | Teste l'endpoint d'import avec un fichier CSV via curl |
| `make help` | Affiche l'aide avec toutes les commandes disponibles |

### Workflow de développement typique

```bash
# 1. Premier démarrage
make init

# 2. Développement quotidien
make docker-up                # Démarrer l'environnement
make docker-shell             # Travailler dans le conteneur si besoin
make docker-logs              # Suivre les logs

# 3. Avant un commit
make check-cs                 # Vérifier le style
make fix-cs                   # Corriger le style
make analyze                  # Analyser le code
make test                     # Lancer les tests

# 4. Tester l'import
make test-import file=tests/fixtures/sample_sales.csv

# 5. Arrêt
make docker-stop
```

### Exemples d'utilisation

```bash
# Installer une nouvelle dépendance
make composer-require package=symfony/mailer

# Voir les logs d'import en temps réel
make logs-sales

# Débugger dans le conteneur
make docker-shell
# Puis dans le shell:
# php bin/console debug:router
# php bin/console cache:clear
# tail -f var/log/sales_import.log

# Tester l'import avec un fichier
make test-import file=/path/to/sales_20240214.csv
```
