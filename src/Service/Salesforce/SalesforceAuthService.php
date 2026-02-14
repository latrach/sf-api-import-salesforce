<?php

declare(strict_types=1);

namespace App\Service\Salesforce;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service d'authentification OAuth2 pour Salesforce.
 *
 * Gère l'acquisition et le cache du token d'accès OAuth2.
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
        private readonly string $clientSecret,
        private readonly string $username,
        private readonly string $password,
        private readonly string $securityToken,
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
     * Acquiert un nouveau token OAuth2 via password grant.
     */
    private function acquireNewToken(): void
    {
        $startTime = microtime(true);

        try {
            $this->salesLogger->info('Salesforce OAuth2 authentication started');

            $response = $this->salesforceClient->request('POST', '/services/oauth2/token', [
                'body' => [
                    'grant_type' => 'password',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'username' => $this->username,
                    'password' => $this->password . $this->securityToken,
                ],
            ]);

            $data = $response->toArray();

            $this->accessToken = $data['access_token'];
            // Salesforce ne retourne pas toujours expires_in, on met 2h par défaut
            $expiresIn = $data['expires_in'] ?? 7200;
            $this->tokenExpiresAt = time() + $expiresIn;

            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->info('Salesforce OAuth2 authentication successful', [
                'duration_seconds' => $duration,
                'expires_in' => $expiresIn,
            ]);
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->error('Salesforce OAuth2 authentication failed', [
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
}
