#!/bin/bash

# Script pour générer une paire de clés RSA pour l'authentification Salesforce JWT Bearer Flow
# Usage: ./bin/generate-salesforce-keys.sh [output_directory]

set -e

OUTPUT_DIR="${1:-.}"
PRIVATE_KEY="${OUTPUT_DIR}/salesforce_private_key.pem"
PUBLIC_KEY="${OUTPUT_DIR}/salesforce_public_key.pem"

echo "🔐 Génération des clés RSA pour Salesforce JWT Bearer Flow"
echo ""

# Vérifier si les clés existent déjà
if [ -f "$PRIVATE_KEY" ] || [ -f "$PUBLIC_KEY" ]; then
    echo "⚠️  Les fichiers de clés existent déjà dans ${OUTPUT_DIR}:"
    [ -f "$PRIVATE_KEY" ] && echo "   - $PRIVATE_KEY"
    [ -f "$PUBLIC_KEY" ] && echo "   - $PUBLIC_KEY"
    echo ""
    read -p "Voulez-vous les remplacer? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "❌ Opération annulée"
        exit 1
    fi
fi

# Créer le répertoire de sortie s'il n'existe pas
mkdir -p "$OUTPUT_DIR"

echo "📝 Génération de la clé privée..."
openssl genrsa -out "$PRIVATE_KEY" 2048

echo "📝 Génération de la clé publique (certificat auto-signé)..."
openssl req -new -x509 -key "$PRIVATE_KEY" -out "$PUBLIC_KEY" -days 365 \
    -subj "/C=FR/ST=Paris/L=Paris/O=Organization/CN=salesforce-integration"

echo ""
echo "✅ Clés générées avec succès !"
echo ""
echo "📂 Fichiers créés:"
echo "   🔒 Clé privée : $PRIVATE_KEY (À GARDER SECRÈTE !)"
echo "   🔓 Clé publique: $PUBLIC_KEY (À uploader dans Salesforce)"
echo ""
echo "🚀 Prochaines étapes:"
echo ""
echo "1. Mettre à jour votre fichier .env:"
echo "   SALESFORCE_PRIVATE_KEY_PATH=$(realpath "$PRIVATE_KEY")"
echo ""
echo "2. Dans Salesforce Setup:"
echo "   - Aller dans App Manager"
echo "   - Créer une nouvelle Connected App"
echo "   - Activer OAuth Settings"
echo "   - Cocher 'Use digital signatures'"
echo "   - Uploader le fichier: $(realpath "$PUBLIC_KEY")"
echo "   - Récupérer le Consumer Key et le mettre dans SALESFORCE_CLIENT_ID"
echo ""
echo "3. Voir .claude/CLAUDE.md section 'Configuration' pour plus de détails"
echo ""

# Afficher les permissions
chmod 600 "$PRIVATE_KEY"
chmod 644 "$PUBLIC_KEY"

echo "🔒 Permissions définies:"
echo "   - $PRIVATE_KEY : 600 (lecture seule par le propriétaire)"
echo "   - $PUBLIC_KEY : 644 (lecture publique)"
echo ""
