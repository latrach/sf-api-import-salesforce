<?php

declare(strict_types=1);

namespace App\Service\Sales;

use Psr\Log\LoggerInterface;

/**
 * Service de transformation des données de ventes vers le format Salesforce.
 */
final class SalesTransformerService
{
    public function __construct(
        private readonly LoggerInterface $salesLogger,
    ) {
    }

    /**
     * Transforme les données enrichies en CSV au format Salesforce Bulk API 2.0.
     *
     * @param array<int, array<string, string>> $enrichedSales
     */
    public function transformToCsv(array $enrichedSales, array $context = []): string
    {
        $startTime = microtime(true);

        $this->salesLogger->info('Starting data transformation to Salesforce format', array_merge($context, [
            'record_count' => count($enrichedSales),
        ]));

        try {
            // Définition de l'en-tête Salesforce
            $headers = [
                'AccountId',
                'Name',
                'StageName',
                'Type',
                'CloseDate',
                'Amount',
                'Customer_Email__c',
                'Product_Name__c',
                'Warranty_Code__c',
                'Warranty_Start_Date__c',
                'Warranty_End_Date__c',
                'Product_Purchase_Price__c',
                'Invoice_Number__c',
                'Purchase_Date__c',
                'Shipping_Address__c',
            ];

            // Utiliser un stream en mémoire pour générer le CSV
            $stream = fopen('php://temp', 'r+');
            if (false === $stream) {
                throw new \RuntimeException('Unable to create temporary stream');
            }

            // Écrire l'en-tête
            fputcsv($stream, $headers);

            // Transformer et écrire chaque ligne
            foreach ($enrichedSales as $sale) {
                $row = [
                    'AccountId' => $sale['AccountId'] ?? '',
                    'Name' => sprintf(
                        '%s - %s - %s',
                        $sale['warranty_label'],
                        $sale['customer_email'],
                        $sale['invoice_number']
                    ),
                    'StageName' => 'Closed Won',
                    'Type' => 'Warranty Extension',
                    'CloseDate' => $sale['warranty_purchase_date'],
                    'Amount' => $sale['product_purchase_price'],
                    'Customer_Email__c' => $sale['customer_email'],
                    'Product_Name__c' => $sale['product_name'],
                    'Warranty_Code__c' => $sale['warranty_code'],
                    'Warranty_Start_Date__c' => $sale['warranty_start_date'],
                    'Warranty_End_Date__c' => $sale['warranty_end_date'],
                    'Product_Purchase_Price__c' => $sale['product_purchase_price'],
                    'Invoice_Number__c' => $sale['invoice_number'],
                    'Purchase_Date__c' => $sale['purchase_date'],
                    'Shipping_Address__c' => sprintf(
                        '%s, %s %s, %s',
                        $sale['customer_address_street'],
                        $sale['customer_address_zipcode'],
                        $sale['customer_address_city'],
                        $sale['customer_address_country']
                    ),
                ];

                fputcsv($stream, $row);
            }

            // Lire le contenu du stream
            rewind($stream);
            $csvContent = stream_get_contents($stream);
            fclose($stream);

            if (false === $csvContent) {
                throw new \RuntimeException('Failed to read CSV content from stream');
            }

            // Salesforce Bulk API 2.0 nécessite des line endings LF
            $csvContent = str_replace("\r\n", "\n", $csvContent);

            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->info('Data transformation completed', array_merge($context, [
                'csv_size_bytes' => strlen($csvContent),
                'duration_seconds' => $duration,
            ]));

            return $csvContent;
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->error('Data transformation failed', array_merge($context, [
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
            ]));

            throw new \RuntimeException(
                sprintf('Failed to transform data to Salesforce format: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }
}
