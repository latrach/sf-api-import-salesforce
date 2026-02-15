<?php

declare(strict_types=1);

namespace App\Tests\Service\Sales;

use App\Service\Sales\SalesTransformerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SalesTransformerServiceTest extends TestCase
{
    private SalesTransformerService $service;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new SalesTransformerService($this->logger);
    }

    public function testTransformToCsv(): void
    {
        $enrichedSales = [
            [
                'AccountId' => '001XXXXXXXXXXXXXXX',
                'partner_name' => 'Fnac',
                'customer_email' => 'jean.dupont@email.com',
                'product_name' => 'iPhone 15 Pro',
                'warranty_code' => 'EXT2Y',
                'warranty_label' => 'Extension Garantie 2 ans',
                'warranty_start_date' => '2024-01-15',
                'warranty_end_date' => '2026-01-15',
                'product_purchase_price' => '1199.99',
                'warranty_purchase_date' => '2024-01-15',
                'invoice_number' => 'INV-2024-001',
                'purchase_date' => '2024-01-15',
                'customer_address_street' => '12 rue de la Paix',
                'customer_address_city' => 'Paris',
                'customer_address_zipcode' => '75002',
                'customer_address_country' => 'France',
            ],
        ];

        $csv = $this->service->transformToCsv($enrichedSales, []);

        // Vérifier que le CSV contient les en-têtes Salesforce
        $this->assertStringContainsString('AccountId', $csv);
        $this->assertStringContainsString('Name', $csv);
        $this->assertStringContainsString('StageName', $csv);
        $this->assertStringContainsString('Type', $csv);
        $this->assertStringContainsString('Customer_Email__c', $csv);

        // Vérifier que les données sont présentes
        $this->assertStringContainsString('001XXXXXXXXXXXXXXX', $csv);
        $this->assertStringContainsString('Extension Garantie 2 ans - jean.dupont@email.com - INV-2024-001', $csv);
        $this->assertStringContainsString('Closed Won', $csv);
        $this->assertStringContainsString('Warranty Extension', $csv);

        // Vérifier l'adresse concaténée
        $this->assertStringContainsString('12 rue de la Paix, 75002 Paris, France', $csv);

        // Vérifier que les line endings sont LF
        $this->assertStringNotContainsString("\r\n", $csv);
    }

    public function testTransformToCsvWithMultipleRecords(): void
    {
        $enrichedSales = [
            [
                'AccountId' => '001AAA',
                'partner_name' => 'Fnac',
                'customer_email' => 'jean.dupont@email.com',
                'product_name' => 'iPhone 15 Pro',
                'warranty_code' => 'EXT2Y',
                'warranty_label' => 'Extension Garantie 2 ans',
                'warranty_start_date' => '2024-01-15',
                'warranty_end_date' => '2026-01-15',
                'product_purchase_price' => '1199.99',
                'warranty_purchase_date' => '2024-01-15',
                'invoice_number' => 'INV-001',
                'purchase_date' => '2024-01-15',
                'customer_address_street' => '12 rue A',
                'customer_address_city' => 'Paris',
                'customer_address_zipcode' => '75001',
                'customer_address_country' => 'France',
            ],
            [
                'AccountId' => '001BBB',
                'partner_name' => 'Darty',
                'customer_email' => 'marie.martin@email.com',
                'product_name' => 'MacBook',
                'warranty_code' => 'APPC3Y',
                'warranty_label' => 'AppleCare+ 3 ans',
                'warranty_start_date' => '2024-02-01',
                'warranty_end_date' => '2027-02-01',
                'product_purchase_price' => '1499.00',
                'warranty_purchase_date' => '2024-02-01',
                'invoice_number' => 'INV-002',
                'purchase_date' => '2024-02-01',
                'customer_address_street' => '45 avenue B',
                'customer_address_city' => 'Lyon',
                'customer_address_zipcode' => '69001',
                'customer_address_country' => 'France',
            ],
        ];

        $csv = $this->service->transformToCsv($enrichedSales, []);

        $lines = explode("\n", trim($csv));
        $this->assertCount(3, $lines); // Header + 2 lignes de données
    }

    public function testTransformToCsvWithSpecialCharacters(): void
    {
        $enrichedSales = [
            [
                'AccountId' => '001XXXXXXXXXXXXXXX',
                'partner_name' => 'Fnac',
                'customer_email' => 'jean.dupont@email.com',
                'product_name' => 'iPhone "Pro" 15', // Guillemets
                'warranty_code' => 'EXT2Y',
                'warranty_label' => 'Extension Garantie 2 ans, complète', // Virgule
                'warranty_start_date' => '2024-01-15',
                'warranty_end_date' => '2026-01-15',
                'product_purchase_price' => '1199.99',
                'warranty_purchase_date' => '2024-01-15',
                'invoice_number' => 'INV-2024-001',
                'purchase_date' => '2024-01-15',
                'customer_address_street' => '12 rue de la "Paix"', // Guillemets
                'customer_address_city' => 'Paris',
                'customer_address_zipcode' => '75002',
                'customer_address_country' => 'France',
            ],
        ];

        $csv = $this->service->transformToCsv($enrichedSales, []);

        // Vérifier que les guillemets et virgules sont correctement échappés
        // En CSV, les guillemets sont doublés : " devient ""
        $this->assertStringContainsString('iPhone ""Pro"" 15', $csv);
        $this->assertStringContainsString('Extension Garantie 2 ans, complète', $csv);
        $this->assertStringContainsString('12 rue de la ""Paix""', $csv);

        // Vérifier que le CSV est parsable
        $lines = str_getcsv($csv, "\n");
        $this->assertGreaterThan(1, count($lines));
    }

    public function testTransformToCsvWithMissingAccountId(): void
    {
        $enrichedSales = [
            [
                // AccountId manquant
                'partner_name' => 'Fnac',
                'customer_email' => 'jean.dupont@email.com',
                'product_name' => 'iPhone 15 Pro',
                'warranty_code' => 'EXT2Y',
                'warranty_label' => 'Extension Garantie 2 ans',
                'warranty_start_date' => '2024-01-15',
                'warranty_end_date' => '2026-01-15',
                'product_purchase_price' => '1199.99',
                'warranty_purchase_date' => '2024-01-15',
                'invoice_number' => 'INV-2024-001',
                'purchase_date' => '2024-01-15',
                'customer_address_street' => '12 rue de la Paix',
                'customer_address_city' => 'Paris',
                'customer_address_zipcode' => '75002',
                'customer_address_country' => 'France',
            ],
        ];

        $csv = $this->service->transformToCsv($enrichedSales, []);

        // Vérifier que le CSV est généré sans erreur
        $this->assertStringContainsString('AccountId', $csv);

        // Parser le CSV et vérifier que AccountId est vide
        $lines = explode("\n", trim($csv));
        $headers = str_getcsv($lines[0]);
        $data = str_getcsv($lines[1]);

        $accountIdIndex = array_search('AccountId', $headers, true);
        $this->assertNotFalse($accountIdIndex);
        $this->assertEquals('', $data[$accountIdIndex]);
    }

    public function testTransformToCsvWithEmptyArray(): void
    {
        $csv = $this->service->transformToCsv([], []);

        // Doit contenir seulement l'en-tête
        $lines = explode("\n", trim($csv));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('AccountId', $lines[0]);
    }

    public function testTransformToCsvLineEndingsAreLF(): void
    {
        $enrichedSales = [
            [
                'AccountId' => '001AAA',
                'partner_name' => 'Fnac',
                'customer_email' => 'test@email.com',
                'product_name' => 'Product',
                'warranty_code' => 'EXT2Y',
                'warranty_label' => 'Warranty',
                'warranty_start_date' => '2024-01-15',
                'warranty_end_date' => '2026-01-15',
                'product_purchase_price' => '100.00',
                'warranty_purchase_date' => '2024-01-15',
                'invoice_number' => 'INV-001',
                'purchase_date' => '2024-01-15',
                'customer_address_street' => 'Street',
                'customer_address_city' => 'City',
                'customer_address_zipcode' => '12345',
                'customer_address_country' => 'Country',
            ],
        ];

        $csv = $this->service->transformToCsv($enrichedSales, []);

        // Vérifier qu'il n'y a pas de CRLF (\r\n), seulement LF (\n)
        $this->assertStringNotContainsString("\r\n", $csv);
        $this->assertStringContainsString("\n", $csv);
    }

    public function testTransformToCsvOpportunityNameFormat(): void
    {
        $enrichedSales = [
            [
                'AccountId' => '001XXXXXXXXXXXXXXX',
                'partner_name' => 'Fnac',
                'customer_email' => 'test@example.com',
                'product_name' => 'iPhone',
                'warranty_code' => 'EXT2Y',
                'warranty_label' => 'Garantie 2 ans',
                'warranty_start_date' => '2024-01-15',
                'warranty_end_date' => '2026-01-15',
                'product_purchase_price' => '999.99',
                'warranty_purchase_date' => '2024-01-15',
                'invoice_number' => 'INV-123',
                'purchase_date' => '2024-01-15',
                'customer_address_street' => 'Street',
                'customer_address_city' => 'City',
                'customer_address_zipcode' => '12345',
                'customer_address_country' => 'France',
            ],
        ];

        $csv = $this->service->transformToCsv($enrichedSales, []);

        // Vérifier le format du nom d'opportunité: "{warranty_label} - {email} - {invoice}"
        $expectedName = 'Garantie 2 ans - test@example.com - INV-123';
        $this->assertStringContainsString($expectedName, $csv);
    }

    public function testTransformToCsvAddressFormat(): void
    {
        $enrichedSales = [
            [
                'AccountId' => '001XXXXXXXXXXXXXXX',
                'partner_name' => 'Fnac',
                'customer_email' => 'test@example.com',
                'product_name' => 'iPhone',
                'warranty_code' => 'EXT2Y',
                'warranty_label' => 'Garantie',
                'warranty_start_date' => '2024-01-15',
                'warranty_end_date' => '2026-01-15',
                'product_purchase_price' => '999.99',
                'warranty_purchase_date' => '2024-01-15',
                'invoice_number' => 'INV-123',
                'purchase_date' => '2024-01-15',
                'customer_address_street' => '123 Main Street',
                'customer_address_city' => 'Paris',
                'customer_address_zipcode' => '75001',
                'customer_address_country' => 'France',
            ],
        ];

        $csv = $this->service->transformToCsv($enrichedSales, []);

        // Vérifier le format de l'adresse: "{street}, {zipcode} {city}, {country}"
        $expectedAddress = '123 Main Street, 75001 Paris, France';
        $this->assertStringContainsString($expectedAddress, $csv);
    }

    public function testTransformToCsvSalesforceFields(): void
    {
        $enrichedSales = [
            [
                'AccountId' => '001XXXXXXXXXXXXXXX',
                'partner_name' => 'Fnac',
                'customer_email' => 'test@example.com',
                'product_name' => 'iPhone',
                'warranty_code' => 'EXT2Y',
                'warranty_label' => 'Garantie',
                'warranty_start_date' => '2024-01-15',
                'warranty_end_date' => '2026-01-15',
                'product_purchase_price' => '999.99',
                'warranty_purchase_date' => '2024-01-15',
                'invoice_number' => 'INV-123',
                'purchase_date' => '2024-01-15',
                'customer_address_street' => 'Street',
                'customer_address_city' => 'City',
                'customer_address_zipcode' => '12345',
                'customer_address_country' => 'France',
            ],
        ];

        $csv = $this->service->transformToCsv($enrichedSales, []);

        // Vérifier que les valeurs fixes Salesforce sont correctes
        $this->assertStringContainsString('Closed Won', $csv);
        $this->assertStringContainsString('Warranty Extension', $csv);

        // Vérifier que tous les champs personnalisés sont présents
        $requiredCustomFields = [
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

        foreach ($requiredCustomFields as $field) {
            $this->assertStringContainsString($field, $csv, "Missing custom field: {$field}");
        }
    }
}
