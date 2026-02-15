<?php

declare(strict_types=1);

namespace App\Service\Sales;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service de parsing de fichiers CSV de ventes partenaires.
 */
final class CsvParserService
{
    private const EXPECTED_HEADERS = [
        'partner_name',
        'customer_email',
        'product_name',
        'warranty_code',
        'warranty_label',
        'warranty_start_date',
        'warranty_end_date',
        'product_purchase_price',
        'warranty_purchase_date',
        'invoice_number',
        'purchase_date',
        'customer_address_street',
        'customer_address_city',
        'customer_address_zipcode',
        'customer_address_country',
    ];

    public function __construct(
        private readonly LoggerInterface $salesLogger,
    ) {
    }

    /**
     * Parse un fichier CSV de ventes.
     *
     * @return array<int, array<string, string>>
     */
    public function parse(UploadedFile $file, array $context = []): array
    {
        $startTime = microtime(true);

        try {
            $this->salesLogger->info('Starting CSV parsing', array_merge($context, [
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
            ]));

            $handle = fopen($file->getPathname(), 'r');
            if (false === $handle) {
                throw new \RuntimeException('Unable to open CSV file');
            }

            // Lecture de l'en-tête
            $headers = fgetcsv($handle);
            if (false === $headers || empty($headers)) {
                fclose($handle);
                throw new \RuntimeException('CSV file is empty or invalid');
            }

            // Validation de l'en-tête
            if ($headers !== self::EXPECTED_HEADERS) {
                fclose($handle);
                throw new \RuntimeException(sprintf(
                    'Invalid CSV headers. Expected: %s, Got: %s',
                    implode(', ', self::EXPECTED_HEADERS),
                    implode(', ', $headers)
                ));
            }

            // Parsing des lignes de données
            $data = [];
            $lineNumber = 1; // L'en-tête est la ligne 0

            while (($row = fgetcsv($handle)) !== false) {
                ++$lineNumber;

                // Ignorer les lignes vides
                if (count(array_filter($row)) === 0) {
                    continue;
                }

                // Vérifier que le nombre de colonnes correspond
                if (count($row) !== count(self::EXPECTED_HEADERS)) {
                    $this->salesLogger->warning('Skipping line with invalid column count', array_merge($context, [
                        'line_number' => $lineNumber,
                        'expected_columns' => count(self::EXPECTED_HEADERS),
                        'actual_columns' => count($row),
                    ]));
                    continue;
                }

                // Créer un tableau associatif
                $data[] = array_combine(self::EXPECTED_HEADERS, $row);
            }

            fclose($handle);

            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->info('CSV parsing completed', array_merge($context, [
                'total_lines' => count($data),
                'duration_seconds' => $duration,
            ]));

            return $data;
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->error('CSV parsing failed', array_merge($context, [
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
            ]));

            throw new \RuntimeException(
                sprintf('Failed to parse CSV file: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }
}
