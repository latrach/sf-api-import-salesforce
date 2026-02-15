<?php

declare(strict_types=1);

namespace App\Service\Sales;

use App\Service\Salesforce\SalesforceQueryService;
use Psr\Log\LoggerInterface;

/**
 * Service de réconciliation des partenaires avec les Accounts Salesforce.
 */
final class PartnerReconciliationService
{
    public function __construct(
        private readonly SalesforceQueryService $queryService,
        private readonly LoggerInterface $salesLogger,
    ) {
    }

    /**
     * Réconcilie les partenaires avec les Accounts Salesforce et enrichit les données.
     *
     * @param array<int, array<string, string>> $validSales
     *
     * @return array<int, array<string, string>>
     */
    public function reconcile(array $validSales, array $context = []): array
    {
        $startTime = microtime(true);

        $this->salesLogger->info('Starting partner reconciliation', array_merge($context, [
            'sales_count' => count($validSales),
        ]));

        try {
            // Extraire les noms de partenaires uniques
            $partnerNames = array_unique(array_column($validSales, 'partner_name'));

            $this->salesLogger->info('Found unique partners', array_merge($context, [
                'partner_count' => count($partnerNames),
                'partners' => $partnerNames,
            ]));

            // Récupérer les AccountIds depuis Salesforce
            $accountMapping = $this->queryService->findAccountsByNames($partnerNames, $context);

            $this->salesLogger->info('Account mapping retrieved', array_merge($context, [
                'mapped_partners' => count($accountMapping),
                'mapping' => $accountMapping,
            ]));

            // Identifier les partenaires non trouvés
            $unmappedPartners = array_diff($partnerNames, array_keys($accountMapping));
            if (!empty($unmappedPartners)) {
                $this->salesLogger->warning('Some partners not found in Salesforce', array_merge($context, [
                    'unmapped_partners' => array_values($unmappedPartners),
                    'unmapped_count' => count($unmappedPartners),
                ]));
            }

            // Enrichir les données avec les AccountIds
            $enrichedSales = [];
            foreach ($validSales as $sale) {
                $partnerName = $sale['partner_name'];
                $sale['AccountId'] = $accountMapping[$partnerName] ?? '';

                if (empty($sale['AccountId'])) {
                    $this->salesLogger->debug('Sale without AccountId', array_merge($context, [
                        'partner_name' => $partnerName,
                        'invoice_number' => $sale['invoice_number'],
                    ]));
                }

                $enrichedSales[] = $sale;
            }

            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->info('Partner reconciliation completed', array_merge($context, [
                'enriched_sales' => count($enrichedSales),
                'sales_with_account_id' => count(array_filter($enrichedSales, fn ($s) => !empty($s['AccountId']))),
                'sales_without_account_id' => count(array_filter($enrichedSales, fn ($s) => empty($s['AccountId']))),
                'duration_seconds' => $duration,
            ]));

            return $enrichedSales;
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $this->salesLogger->error('Partner reconciliation failed', array_merge($context, [
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
            ]));

            throw new \RuntimeException(
                sprintf('Failed to reconcile partners: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }
}
