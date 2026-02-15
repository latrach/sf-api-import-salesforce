<?php

declare(strict_types=1);

namespace App\Service\Sales;

use App\Service\Salesforce\SalesforceBulkService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service principal d'orchestration de l'import de ventes.
 */
final class SalesImportService
{
    public function __construct(
        private readonly CsvParserService $csvParser,
        private readonly SalesValidatorService $validator,
        private readonly PartnerReconciliationService $reconciliation,
        private readonly SalesTransformerService $transformer,
        private readonly SalesforceBulkService $bulkService,
        private readonly LoggerInterface $salesLogger,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Importe un fichier CSV de ventes dans Salesforce.
     *
     * @return array{total_lines: int, validation: array{valid: int, errors: int}, salesforce: array{job_id: string, success: int, errors: int}, duration_seconds: float, files: array{validation_errors: string|null, salesforce_errors: string|null}}
     */
    public function import(UploadedFile $file, string $importId): array
    {
        $startTime = microtime(true);
        $context = ['import_id' => $importId];

        $this->salesLogger->info('Starting sales import', array_merge($context, [
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
        ]));

        try {
            // 1. Créer le répertoire de travail
            $workDir = $this->createWorkDirectory($importId, $context);

            // 2. Parser le CSV
            $salesData = $this->csvParser->parse($file, $context);
            $totalLines = count($salesData);

            // 3. Valider les données
            $validationResult = $this->validator->validate($salesData, $context);
            $validSales = $validationResult['valid'];
            $validationErrors = $validationResult['errors'];

            // 4. Exporter les erreurs de validation si présentes
            $validationErrorsFile = null;
            if (!empty($validationErrors)) {
                $validationErrorsPath = sprintf('%s/VALIDATION_ERRORS_%s.csv', $workDir, $importId);
                $validationErrorsFile = $this->validator->exportErrors($validationErrors, $validationErrorsPath, $context);
            }

            // Si aucune donnée valide, arrêter ici
            if (empty($validSales)) {
                $duration = round(microtime(true) - $startTime, 2);

                $this->salesLogger->warning('No valid data to import', array_merge($context, [
                    'total_lines' => $totalLines,
                    'validation_errors' => count($validationErrors),
                    'duration_seconds' => $duration,
                ]));

                return [
                    'total_lines' => $totalLines,
                    'validation' => [
                        'valid' => 0,
                        'errors' => count($validationErrors),
                    ],
                    'salesforce' => [
                        'job_id' => '',
                        'success' => 0,
                        'errors' => 0,
                    ],
                    'duration_seconds' => $duration,
                    'files' => [
                        'validation_errors' => $validationErrorsFile,
                        'salesforce_errors' => null,
                    ],
                ];
            }

            // 5. Réconcilier les partenaires
            $enrichedSales = $this->reconciliation->reconcile($validSales, $context);

            // 6. Transformer au format Salesforce
            $salesforceCsv = $this->transformer->transformToCsv($enrichedSales, $context);

            // 7. Import Bulk API 2.0
            $salesforceResult = $this->importToSalesforce($salesforceCsv, $workDir, $importId, $context);

            // 8. Archiver le fichier source
            $this->archiveSourceFile($file, $importId, $context);

            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->info('Sales import completed successfully', array_merge($context, [
                'total_lines' => $totalLines,
                'validation_valid' => count($validSales),
                'validation_errors' => count($validationErrors),
                'salesforce_success' => $salesforceResult['success'],
                'salesforce_errors' => $salesforceResult['errors'],
                'duration_seconds' => $duration,
            ]));

            return [
                'total_lines' => $totalLines,
                'validation' => [
                    'valid' => count($validSales),
                    'errors' => count($validationErrors),
                ],
                'salesforce' => $salesforceResult,
                'duration_seconds' => $duration,
                'files' => [
                    'validation_errors' => $validationErrorsFile,
                    'salesforce_errors' => $salesforceResult['errors_file'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->error('Sales import failed', array_merge($context, [
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
            ]));

            throw $e;
        }
    }

    /**
     * Crée le répertoire de travail pour l'import.
     */
    private function createWorkDirectory(string $importId, array $context): string
    {
        $date = date('Y-m-d');
        $workDir = sprintf('%s/var/imports/sales/%s', $this->projectDir, $date);

        if (!is_dir($workDir) && !mkdir($workDir, 0775, true) && !is_dir($workDir)) {
            throw new \RuntimeException(sprintf('Failed to create work directory: %s', $workDir));
        }

        $this->salesLogger->debug('Work directory created', array_merge($context, [
            'work_dir' => $workDir,
        ]));

        return $workDir;
    }

    /**
     * Importe les données dans Salesforce via Bulk API 2.0.
     *
     * @return array{job_id: string, success: int, errors: int, errors_file: string|null}
     */
    private function importToSalesforce(string $csvContent, string $workDir, string $importId, array $context): array
    {
        // Créer le job
        $jobId = $this->bulkService->createJob('insert', 'Opportunity', $context);

        // Upload les données
        $this->bulkService->uploadData($jobId, $csvContent, $context);

        // Fermer le job
        $this->bulkService->closeJob($jobId, $context);

        // Attendre la fin du traitement
        $jobResult = $this->bulkService->pollJobCompletion($jobId, $context);

        // Récupérer les erreurs si présentes
        $errorsFile = null;
        if ($jobResult['numberRecordsFailed'] > 0) {
            $failedCsv = $this->bulkService->getFailedResults($jobId, $context);
            if (null !== $failedCsv) {
                $errorsPath = sprintf('%s/SALESFORCE_ERRORS_%s.csv', $workDir, $importId);
                file_put_contents($errorsPath, $failedCsv);
                $errorsFile = basename($errorsPath);

                $this->salesLogger->info('Salesforce errors exported', array_merge($context, [
                    'errors_file' => $errorsFile,
                    'errors_count' => $jobResult['numberRecordsFailed'],
                ]));
            }
        }

        return [
            'job_id' => $jobId,
            'success' => $jobResult['numberRecordsProcessed'] - $jobResult['numberRecordsFailed'],
            'errors' => $jobResult['numberRecordsFailed'],
            'errors_file' => $errorsFile,
        ];
    }

    /**
     * Archive le fichier source dans le répertoire archive.
     */
    private function archiveSourceFile(UploadedFile $file, string $importId, array $context): void
    {
        $yearMonth = date('Y-m');
        $archiveDir = sprintf('%s/var/imports/sales/archive/%s', $this->projectDir, $yearMonth);

        if (!is_dir($archiveDir) && !mkdir($archiveDir, 0775, true) && !is_dir($archiveDir)) {
            throw new \RuntimeException(sprintf('Failed to create archive directory: %s', $archiveDir));
        }

        $archivePath = sprintf('%s/%s_%s', $archiveDir, $importId, $file->getClientOriginalName());

        if (!copy($file->getPathname(), $archivePath)) {
            $this->salesLogger->warning('Failed to archive source file', array_merge($context, [
                'archive_path' => $archivePath,
            ]));

            return;
        }

        $this->salesLogger->info('Source file archived', array_merge($context, [
            'archive_path' => $archivePath,
        ]));
    }
}
