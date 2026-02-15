<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\Sales\SalesImportService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrôleur d'import des ventes de garanties partenaires.
 */
final class SalesImportController extends AbstractController
{
    public function __construct(
        private readonly SalesImportService $importService,
        private readonly LoggerInterface $salesLogger,
    ) {
    }

    #[Route('/api/salesforce/import-sales', name: 'api_salesforce_import_sales', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        $importId = date('YmdHis') . '_' . uniqid();
        $context = ['import_id' => $importId];

        try {
            // Récupérer le fichier uploadé
            /** @var UploadedFile|null $file */
            $file = $request->files->get('file');

            if (null === $file) {
                $this->salesLogger->warning('No file uploaded', $context);

                return $this->json([
                    'status' => 'error',
                    'import_id' => $importId,
                    'error' => 'No file uploaded. Please provide a CSV file using the "file" parameter.',
                    'duration_seconds' => round(microtime(true) - $startTime, 2),
                ], Response::HTTP_BAD_REQUEST);
            }

            // Valider que c'est bien un fichier
            if (!$file->isValid()) {
                $this->salesLogger->warning('Invalid file upload', array_merge($context, [
                    'error' => $file->getErrorMessage(),
                ]));

                return $this->json([
                    'status' => 'error',
                    'import_id' => $importId,
                    'error' => sprintf('Invalid file upload: %s', $file->getErrorMessage()),
                    'duration_seconds' => round(microtime(true) - $startTime, 2),
                ], Response::HTTP_BAD_REQUEST);
            }

            // Valider l'extension du fichier
            $extension = strtolower($file->getClientOriginalExtension());
            if ('csv' !== $extension) {
                $this->salesLogger->warning('Invalid file extension', array_merge($context, [
                    'extension' => $extension,
                ]));

                return $this->json([
                    'status' => 'error',
                    'import_id' => $importId,
                    'error' => sprintf('Invalid file extension. Expected CSV, got: %s', $extension),
                    'duration_seconds' => round(microtime(true) - $startTime, 2),
                ], Response::HTTP_BAD_REQUEST);
            }

            // Lancer l'import
            $result = $this->importService->import($file, $importId);

            $duration = round(microtime(true) - $startTime, 2);

            return $this->json([
                'status' => 'success',
                'import_id' => $importId,
                'summary' => [
                    'total_lines' => $result['total_lines'],
                    'validation' => $result['validation'],
                    'salesforce' => [
                        'job_id' => $result['salesforce']['job_id'],
                        'success' => $result['salesforce']['success'],
                        'errors' => $result['salesforce']['errors'],
                    ],
                    'duration_seconds' => $duration,
                ],
                'files' => $result['files'],
            ]);
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->error('Import failed with exception', array_merge($context, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'duration_seconds' => $duration,
            ]));

            return $this->json([
                'status' => 'error',
                'import_id' => $importId,
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
