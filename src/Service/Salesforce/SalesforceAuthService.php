<?php

declare(strict_types=1);

namespace App\Service\Salesforce;

use Firebase\JWT\JWT;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service d'authentification OAuth2 pour Salesforce via JWT Bearer Flow.
 *
 * Gère l'acquisition et le cache du token d'accès OAuth2.
 * Utilise JWT (JSON Web Token) pour l'authentification sécurisée.
 */
final class SalesforceAuthService
{
    private ?string $accessToken = null;
    private ?int $tokenExpiresAt = null;

    public function __construct(
        private readonly HttpClientInterface $salesforceClient,
        private readonly LoggerInterface $salesLogger,
        private readonly string $instanceUrl,
        private readonly string $clientId,
        private readonly string $username,
        private readonly string $privateKeyPath,
        private readonly string $audienceUrl,
    ) {
    }

    /**
     * Récupère un token d'accès valide (utilise le cache si disponible).
     */
    public function getAccessToken(): string
    {
        if ($this->isTokenValid()) {
            return $this->accessToken;
        }

        $this->acquireNewToken();

        return $this->accessToken;
    }

    /**
     * Force le renouvellement du token d'accès.
     */
    public function refreshToken(): string
    {
        $this->acquireNewToken();

        return $this->accessToken;
    }

    /**
     * Vérifie si le token actuel est valide.
     */
    private function isTokenValid(): bool
    {
        if (null === $this->accessToken || null === $this->tokenExpiresAt) {
            return false;
        }

        // On ajoute une marge de 60 secondes pour éviter les expirations
        return time() < ($this->tokenExpiresAt - 60);
    }

    /**
     * Acquiert un nouveau token OAuth2 via JWT Bearer Flow.
     */
    private function acquireNewToken(): void
    {
        $startTime = microtime(true);

        try {
            $this->salesLogger->info('Salesforce JWT authentication started');

            // Créer le JWT
            $jwt = $this->createJWT();

            // Envoyer le JWT à Salesforce pour obtenir un access token
            $response = $this->salesforceClient->request('POST', '/services/oauth2/token', [
                'body' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ],
            ]);

            $data = $response->toArray();

            $this->accessToken = $data['access_token'];
            // Salesforce ne retourne pas toujours expires_in, on met 2h par défaut
            $expiresIn = $data['expires_in'] ?? 7200;
            $this->tokenExpiresAt = time() + $expiresIn;

            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->info('Salesforce JWT authentication successful', [
                'duration_seconds' => $duration,
                'expires_in' => $expiresIn,
            ]);
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->error('Salesforce JWT authentication failed', [
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
            ]);

            throw new \RuntimeException(
                sprintf('Failed to authenticate with Salesforce: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Crée un JWT signé avec la clé privée pour l'authentification Salesforce.
     */
    private function createJWT(): string
    {
        $now = time();

        $payload = [
            'iss' => $this->clientId,         // Consumer Key de la Connected App
            'sub' => $this->username,         // Username Salesforce
            'aud' => $this->audienceUrl,      // https://login.salesforce.com ou https://test.salesforce.com
            'exp' => $now + 300,              // Expiration dans 5 minutes
        ];

        // Lire la clé privée
        $privateKey = $this->getPrivateKey();

        // Signer le JWT avec RS256 (RSA + SHA256)
        return JWT::encode($payload, $privateKey, 'RS256');
    }

    /**
     * Récupère la clé privée depuis le fichier.
     */
    private function getPrivateKey(): string
    {
        if (!file_exists($this->privateKeyPath)) {
            throw new \RuntimeException(
                sprintf('Private key file not found: %s', $this->privateKeyPath)
            );
        }

        $privateKey = file_get_contents($this->privateKeyPath);

        if (false === $privateKey) {
            throw new \RuntimeException(
                sprintf('Failed to read private key file: %s', $this->privateKeyPath)
            );
        }

        return $privateKey;
    }
}
