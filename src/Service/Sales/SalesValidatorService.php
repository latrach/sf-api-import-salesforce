<?php

declare(strict_types=1);

namespace App\Service\Sales;

use Psr\Log\LoggerInterface;

/**
 * Service de validation des données de ventes partenaires.
 */
final class SalesValidatorService
{
    private const REQUIRED_FIELDS = [
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

    private const DATE_REGEX = '/^\d{4}-\d{2}-\d{2}$/';

    public function __construct(
        private readonly LoggerInterface $salesLogger,
    ) {
    }

    /**
     * Valide les données de ventes et sépare les lignes valides des erreurs.
     *
     * @param array<int, array<string, string>> $salesData
     *
     * @return array{valid: array<int, array<string, string>>, errors: array<int, array<string, string>>}
     */
    public function validate(array $salesData, array $context = []): array
    {
        $startTime = microtime(true);

        $this->salesLogger->info('Starting data validation', array_merge($context, [
            'total_lines' => count($salesData),
        ]));

        $valid = [];
        $errors = [];

        foreach ($salesData as $index => $row) {
            $lineNumber = $index + 2; // +2 car l'en-tête est ligne 1
            $errorReasons = [];

            // Validation des champs obligatoires
            foreach (self::REQUIRED_FIELDS as $field) {
                if (!isset($row[$field]) || trim($row[$field]) === '') {
                    $errorReasons[] = sprintf('Missing or empty field: %s', $field);
                }
            }

            // Si des champs sont manquants, on skip les validations suivantes
            if (!empty($errorReasons)) {
                $errors[] = array_merge($row, ['error_reason' => implode('; ', $errorReasons)]);
                continue;
            }

            // Validation du format des dates
            $dateFields = ['warranty_start_date', 'warranty_end_date', 'warranty_purchase_date', 'purchase_date'];
            foreach ($dateFields as $field) {
                if (!preg_match(self::DATE_REGEX, $row[$field])) {
                    $errorReasons[] = sprintf('Invalid date format for %s (expected YYYY-MM-DD): %s', $field, $row[$field]);
                }
            }

            // Validation de l'email
            if (!filter_var($row['customer_email'], FILTER_VALIDATE_EMAIL)) {
                $errorReasons[] = sprintf('Invalid email format: %s', $row['customer_email']);
            }

            // Validation du prix
            if (!is_numeric($row['product_purchase_price']) || (float) $row['product_purchase_price'] <= 0) {
                $errorReasons[] = sprintf('Invalid price (must be > 0): %s', $row['product_purchase_price']);
            } else {
                // Vérifier max 2 décimales
                $priceParts = explode('.', $row['product_purchase_price']);
                if (isset($priceParts[1]) && strlen($priceParts[1]) > 2) {
                    $errorReasons[] = sprintf('Price has too many decimals (max 2): %s', $row['product_purchase_price']);
                }
            }

            // Validation du warranty_code (alphanumérique, max 50 caractères)
            if (!preg_match('/^[a-zA-Z0-9]+$/', $row['warranty_code'])) {
                $errorReasons[] = sprintf('Warranty code must be alphanumeric: %s', $row['warranty_code']);
            }
            if (strlen($row['warranty_code']) > 50) {
                $errorReasons[] = sprintf('Warranty code too long (max 50 chars): %s', $row['warranty_code']);
            }

            // Si pas d'erreurs de format, valider la cohérence des dates
            if (empty($errorReasons)) {
                $purchaseDate = new \DateTimeImmutable($row['purchase_date']);
                $warrantyPurchaseDate = new \DateTimeImmutable($row['warranty_purchase_date']);
                $warrantyStartDate = new \DateTimeImmutable($row['warranty_start_date']);
                $warrantyEndDate = new \DateTimeImmutable($row['warranty_end_date']);

                // purchase_date <= warranty_purchase_date
                if ($purchaseDate > $warrantyPurchaseDate) {
                    $errorReasons[] = sprintf(
                        'purchase_date (%s) must be <= warranty_purchase_date (%s)',
                        $row['purchase_date'],
                        $row['warranty_purchase_date']
                    );
                }

                // warranty_purchase_date <= warranty_start_date
                if ($warrantyPurchaseDate > $warrantyStartDate) {
                    $errorReasons[] = sprintf(
                        'warranty_purchase_date (%s) must be <= warranty_start_date (%s)',
                        $row['warranty_purchase_date'],
                        $row['warranty_start_date']
                    );
                }

                // warranty_start_date < warranty_end_date
                if ($warrantyStartDate >= $warrantyEndDate) {
                    $errorReasons[] = sprintf(
                        'warranty_start_date (%s) must be < warranty_end_date (%s)',
                        $row['warranty_start_date'],
                        $row['warranty_end_date']
                    );
                }
            }

            // Ajout à la liste appropriée
            if (empty($errorReasons)) {
                $valid[] = $row;
            } else {
                $errors[] = array_merge($row, ['error_reason' => implode('; ', $errorReasons)]);
            }
        }

        $duration = round(microtime(true) - $startTime, 2);

        $this->salesLogger->info('Data validation completed', array_merge($context, [
            'valid_lines' => count($valid),
            'error_lines' => count($errors),
            'duration_seconds' => $duration,
        ]));

        return [
            'valid' => $valid,
            'errors' => $errors,
        ];
    }

    /**
     * Exporte les erreurs de validation dans un fichier CSV.
     */
    public function exportErrors(array $errors, string $outputPath, array $context = []): ?string
    {
        if (empty($errors)) {
            return null;
        }

        $startTime = microtime(true);

        try {
            $this->salesLogger->info('Exporting validation errors', array_merge($context, [
                'error_count' => count($errors),
                'output_path' => $outputPath,
            ]));

            // Créer le répertoire si nécessaire
            $dir = dirname($outputPath);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Failed to create directory: %s', $dir));
            }

            $handle = fopen($outputPath, 'w');
            if (false === $handle) {
                throw new \RuntimeException(sprintf('Unable to create error CSV file: %s', $outputPath));
            }

            // Écrire l'en-tête (colonnes + error_reason)
            $headers = array_keys($errors[0]);
            fputcsv($handle, $headers);

            // Écrire les lignes d'erreur
            foreach ($errors as $error) {
                fputcsv($handle, $error);
            }

            fclose($handle);

            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->info('Validation errors exported successfully', array_merge($context, [
                'output_path' => $outputPath,
                'duration_seconds' => $duration,
            ]));

            return basename($outputPath);
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->error('Failed to export validation errors', array_merge($context, [
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
            ]));

            throw new \RuntimeException(
                sprintf('Failed to export validation errors: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }
}
