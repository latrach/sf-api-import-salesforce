<?php

declare(strict_types=1);

namespace App\Service\Salesforce;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service d'interaction avec Salesforce Bulk API 2.0.
 *
 * Gère le cycle complet : create job → upload → close → poll → get results
 */
final class SalesforceBulkService
{
    private const API_VERSION = 'v59.0';
    private const POLL_INTERVAL_SECONDS = 5;
    private const POLL_TIMEOUT_SECONDS = 600;

    public function __construct(
        private readonly HttpClientInterface $salesforceClient,
        private readonly SalesforceAuthService $authService,
        private readonly LoggerInterface $salesLogger,
    ) {
    }

    /**
     * Crée un job Bulk API 2.0 pour l'insertion d'opportunités.
     */
    public function createJob(string $operation = 'insert', string $object = 'Opportunity', array $context = []): string
    {
        $startTime = microtime(true);

        try {
            $this->salesLogger->info('Creating Salesforce Bulk API job', array_merge($context, [
                'operation' => $operation,
                'object' => $object,
            ]));

            $response = $this->salesforceClient->request('POST', sprintf(
                '/services/data/%s/jobs/ingest',
                self::API_VERSION
            ), [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $this->authService->getAccessToken()),
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'operation' => $operation,
                    'object' => $object,
                    'contentType' => 'CSV',
                    'lineEnding' => 'LF',
                ],
            ]);

            $data = $response->toArray();
            $jobId = $data['id'];

            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->info('Bulk API job created successfully', array_merge($context, [
                'job_id' => $jobId,
                'duration_seconds' => $duration,
            ]));

            return $jobId;
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->error('Failed to create Bulk API job', array_merge($context, [
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
            ]));

            throw new \RuntimeException(
                sprintf('Failed to create Salesforce Bulk API job: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Upload les données CSV dans le job.
     */
    public function uploadData(string $jobId, string $csvContent, array $context = []): void
    {
        $startTime = microtime(true);
        $dataSize = strlen($csvContent);

        try {
            $this->salesLogger->info('Uploading data to Bulk API job', array_merge($context, [
                'job_id' => $jobId,
                'data_size_bytes' => $dataSize,
            ]));

            $this->salesforceClient->request('PUT', sprintf(
                '/services/data/%s/jobs/ingest/%s/batches',
                self::API_VERSION,
                $jobId
            ), [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $this->authService->getAccessToken()),
                    'Content-Type' => 'text/csv',
                ],
                'body' => $csvContent,
            ]);

            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->info('Data uploaded successfully', array_merge($context, [
                'job_id' => $jobId,
                'duration_seconds' => $duration,
            ]));
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->error('Failed to upload data to Bulk API job', array_merge($context, [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
            ]));

            throw new \RuntimeException(
                sprintf('Failed to upload data to Salesforce Bulk API job: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Ferme le job pour démarrer le traitement.
     */
    public function closeJob(string $jobId, array $context = []): void
    {
        $startTime = microtime(true);

        try {
            $this->salesLogger->info('Closing Bulk API job', array_merge($context, [
                'job_id' => $jobId,
            ]));

            $this->salesforceClient->request('PATCH', sprintf(
                '/services/data/%s/jobs/ingest/%s',
                self::API_VERSION,
                $jobId
            ), [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $this->authService->getAccessToken()),
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'state' => 'UploadComplete',
                ],
            ]);

            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->info('Bulk API job closed successfully', array_merge($context, [
                'job_id' => $jobId,
                'duration_seconds' => $duration,
            ]));
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->error('Failed to close Bulk API job', array_merge($context, [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
            ]));

            throw new \RuntimeException(
                sprintf('Failed to close Salesforce Bulk API job: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Attend la fin du traitement du job (polling).
     *
     * @return array{state: string, numberRecordsProcessed: int, numberRecordsFailed: int}
     */
    public function pollJobCompletion(string $jobId, array $context = []): array
    {
        $startTime = microtime(true);
        $pollCount = 0;

        $this->salesLogger->info('Starting Bulk API job polling', array_merge($context, [
            'job_id' => $jobId,
            'poll_interval_seconds' => self::POLL_INTERVAL_SECONDS,
            'timeout_seconds' => self::POLL_TIMEOUT_SECONDS,
        ]));

        try {
            while (true) {
                ++$pollCount;
                $elapsed = microtime(true) - $startTime;

                if ($elapsed > self::POLL_TIMEOUT_SECONDS) {
                    throw new \RuntimeException(
                        sprintf('Bulk API job polling timeout after %d seconds', self::POLL_TIMEOUT_SECONDS)
                    );
                }

                $response = $this->salesforceClient->request('GET', sprintf(
                    '/services/data/%s/jobs/ingest/%s',
                    self::API_VERSION,
                    $jobId
                ), [
                    'headers' => [
                        'Authorization' => sprintf('Bearer %s', $this->authService->getAccessToken()),
                    ],
                ]);

                $jobInfo = $response->toArray();
                $state = $jobInfo['state'];

                $this->salesLogger->debug('Bulk API job poll', array_merge($context, [
                    'job_id' => $jobId,
                    'poll_count' => $pollCount,
                    'state' => $state,
                    'elapsed_seconds' => round($elapsed, 2),
                ]));

                // États terminaux
                if (in_array($state, ['JobComplete', 'Failed', 'Aborted'], true)) {
                    $duration = round(microtime(true) - $startTime, 2);

                    $this->salesLogger->info('Bulk API job completed', array_merge($context, [
                        'job_id' => $jobId,
                        'state' => $state,
                        'poll_count' => $pollCount,
                        'duration_seconds' => $duration,
                        'records_processed' => $jobInfo['numberRecordsProcessed'] ?? 0,
                        'records_failed' => $jobInfo['numberRecordsFailed'] ?? 0,
                    ]));

                    return [
                        'state' => $state,
                        'numberRecordsProcessed' => $jobInfo['numberRecordsProcessed'] ?? 0,
                        'numberRecordsFailed' => $jobInfo['numberRecordsFailed'] ?? 0,
                    ];
                }

                sleep(self::POLL_INTERVAL_SECONDS);
            }
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->error('Bulk API job polling failed', array_merge($context, [
                'job_id' => $jobId,
                'poll_count' => $pollCount,
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
            ]));

            throw new \RuntimeException(
                sprintf('Failed to poll Salesforce Bulk API job: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Récupère les résultats des enregistrements en erreur (CSV).
     */
    public function getFailedResults(string $jobId, array $context = []): ?string
    {
        $startTime = microtime(true);

        try {
            $this->salesLogger->info('Retrieving failed results from Bulk API job', array_merge($context, [
                'job_id' => $jobId,
            ]));

            $response = $this->salesforceClient->request('GET', sprintf(
                '/services/data/%s/jobs/ingest/%s/failedResults',
                self::API_VERSION,
                $jobId
            ), [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $this->authService->getAccessToken()),
                ],
            ]);

            $csvContent = $response->getContent();
            $duration = round(microtime(true) - $startTime, 2);

            // Si le CSV est vide (pas d'erreurs), retourner null
            if (empty(trim($csvContent))) {
                $this->salesLogger->info('No failed results to retrieve', array_merge($context, [
                    'job_id' => $jobId,
                    'duration_seconds' => $duration,
                ]));

                return null;
            }

            $this->salesLogger->info('Failed results retrieved successfully', array_merge($context, [
                'job_id' => $jobId,
                'csv_size_bytes' => strlen($csvContent),
                'duration_seconds' => $duration,
            ]));

            return $csvContent;
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->error('Failed to retrieve failed results', array_merge($context, [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
            ]));

            // On ne throw pas car l'absence de résultats d'erreur n'est pas bloquante
            return null;
        }
    }

    /**
     * Récupère les résultats des enregistrements réussis (CSV).
     */
    public function getSuccessfulResults(string $jobId, array $context = []): ?string
    {
        $startTime = microtime(true);

        try {
            $this->salesLogger->info('Retrieving successful results from Bulk API job', array_merge($context, [
                'job_id' => $jobId,
            ]));

            $response = $this->salesforceClient->request('GET', sprintf(
                '/services/data/%s/jobs/ingest/%s/successfulResults',
                self::API_VERSION,
                $jobId
            ), [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $this->authService->getAccessToken()),
                ],
            ]);

            $csvContent = $response->getContent();
            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->info('Successful results retrieved', array_merge($context, [
                'job_id' => $jobId,
                'csv_size_bytes' => strlen($csvContent),
                'duration_seconds' => $duration,
            ]));

            return $csvContent;
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->error('Failed to retrieve successful results', array_merge($context, [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
            ]));

            return null;
        }
    }
}
