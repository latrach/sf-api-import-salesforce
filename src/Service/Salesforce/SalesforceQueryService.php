<?php

declare(strict_types=1);

namespace App\Service\Salesforce;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service d'exécution de requêtes SOQL sur Salesforce.
 */
final class SalesforceQueryService
{
    private const API_VERSION = 'v59.0';

    public function __construct(
        private readonly HttpClientInterface $salesforceClient,
        private readonly SalesforceAuthService $authService,
        private readonly LoggerInterface $salesLogger,
    ) {
    }

    /**
     * Exécute une requête SOQL et retourne les enregistrements.
     *
     * @return array<int, array<string, mixed>>
     */
    public function query(string $soql, array $context = []): array
    {
        $startTime = microtime(true);

        try {
            $this->salesLogger->info('Executing SOQL query', array_merge($context, [
                'soql' => $soql,
            ]));

            $response = $this->salesforceClient->request('GET', sprintf(
                '/services/data/%s/query',
                self::API_VERSION
            ), [
                'query' => ['q' => $soql],
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $this->authService->getAccessToken()),
                ],
            ]);

            $data = $response->toArray();
            $records = $data['records'] ?? [];

            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->info('SOQL query executed successfully', array_merge($context, [
                'record_count' => count($records),
                'duration_seconds' => $duration,
            ]));

            return $records;
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->error('SOQL query failed', array_merge($context, [
                'soql' => $soql,
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
            ]));

            throw new \RuntimeException(
                sprintf('Failed to execute SOQL query: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Recherche des Accounts par nom (utile pour la réconciliation des partenaires).
     *
     * @param array<string> $partnerNames
     *
     * @return array<string, string> Map [partner_name => Account.Id]
     */
    public function findAccountsByNames(array $partnerNames, array $context = []): array
    {
        if (empty($partnerNames)) {
            return [];
        }

        // Échappement des noms pour SOQL
        $escapedNames = array_map(
            fn (string $name) => "'" . str_replace("'", "\\'", $name) . "'",
            $partnerNames
        );

        $soql = sprintf(
            "SELECT Id, Name FROM Account WHERE Name IN (%s)",
            implode(',', $escapedNames)
        );

        $records = $this->query($soql, $context);

        // Construction du mapping nom => Id
        $mapping = [];
        foreach ($records as $record) {
            $mapping[$record['Name']] = $record['Id'];
        }

        return $mapping;
    }
}
