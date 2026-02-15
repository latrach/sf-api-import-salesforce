<?php

declare(strict_types=1);

namespace App\Tests\Service\Sales;

use App\Service\Sales\SalesValidatorService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SalesValidatorServiceTest extends TestCase
{
    private SalesValidatorService $service;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new SalesValidatorService($this->logger);
    }

    public function testValidateValidData(): void
    {
        $salesData = [
            [
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

        $result = $this->service->validate($salesData, []);

        $this->assertCount(1, $result['valid']);
        $this->assertCount(0, $result['errors']);
    }

    public function testValidateInvalidEmail(): void
    {
        $salesData = [
            [
                'partner_name' => 'Fnac',
                'customer_email' => 'invalid-email',
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

        $result = $this->service->validate($salesData, []);

        $this->assertCount(0, $result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Invalid email format', $result['errors'][0]['error_reason']);
    }

    public function testValidateInvalidDateFormat(): void
    {
        $salesData = [
            [
                'partner_name' => 'Fnac',
                'customer_email' => 'jean.dupont@email.com',
                'product_name' => 'iPhone 15 Pro',
                'warranty_code' => 'EXT2Y',
                'warranty_label' => 'Extension Garantie 2 ans',
                'warranty_start_date' => '15/01/2024',
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

        $result = $this->service->validate($salesData, []);

        $this->assertCount(0, $result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Invalid date format', $result['errors'][0]['error_reason']);
    }

    public function testValidateInvalidPrice(): void
    {
        $salesData = [
            [
                'partner_name' => 'Fnac',
                'customer_email' => 'jean.dupont@email.com',
                'product_name' => 'iPhone 15 Pro',
                'warranty_code' => 'EXT2Y',
                'warranty_label' => 'Extension Garantie 2 ans',
                'warranty_start_date' => '2024-01-15',
                'warranty_end_date' => '2026-01-15',
                'product_purchase_price' => '-100',
                'warranty_purchase_date' => '2024-01-15',
                'invoice_number' => 'INV-2024-001',
                'purchase_date' => '2024-01-15',
                'customer_address_street' => '12 rue de la Paix',
                'customer_address_city' => 'Paris',
                'customer_address_zipcode' => '75002',
                'customer_address_country' => 'France',
            ],
        ];

        $result = $this->service->validate($salesData, []);

        $this->assertCount(0, $result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Invalid price', $result['errors'][0]['error_reason']);
    }

    public function testValidateInvalidDateCoherence(): void
    {
        $salesData = [
            [
                'partner_name' => 'Fnac',
                'customer_email' => 'jean.dupont@email.com',
                'product_name' => 'iPhone 15 Pro',
                'warranty_code' => 'EXT2Y',
                'warranty_label' => 'Extension Garantie 2 ans',
                'warranty_start_date' => '2024-01-15',
                'warranty_end_date' => '2023-01-15',
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

        $result = $this->service->validate($salesData, []);

        $this->assertCount(0, $result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('warranty_start_date', $result['errors'][0]['error_reason']);
    }

    public function testValidateMissingFields(): void
    {
        $salesData = [
            [
                'partner_name' => 'Fnac',
                'customer_email' => '',
                'product_name' => 'iPhone 15 Pro',
            ],
        ];

        $result = $this->service->validate($salesData, []);

        $this->assertCount(0, $result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Missing or empty field', $result['errors'][0]['error_reason']);
    }

    public function testValidatePriceWithTooManyDecimals(): void
    {
        $salesData = [
            [
                'partner_name' => 'Fnac',
                'customer_email' => 'jean.dupont@email.com',
                'product_name' => 'iPhone 15 Pro',
                'warranty_code' => 'EXT2Y',
                'warranty_label' => 'Extension Garantie 2 ans',
                'warranty_start_date' => '2024-01-15',
                'warranty_end_date' => '2026-01-15',
                'product_purchase_price' => '1199.999', // 3 décimales
                'warranty_purchase_date' => '2024-01-15',
                'invoice_number' => 'INV-2024-001',
                'purchase_date' => '2024-01-15',
                'customer_address_street' => '12 rue de la Paix',
                'customer_address_city' => 'Paris',
                'customer_address_zipcode' => '75002',
                'customer_address_country' => 'France',
            ],
        ];

        $result = $this->service->validate($salesData, []);

        $this->assertCount(0, $result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('too many decimals', $result['errors'][0]['error_reason']);
    }

    public function testValidateWarrantyCodeTooLong(): void
    {
        $salesData = [
            [
                'partner_name' => 'Fnac',
                'customer_email' => 'jean.dupont@email.com',
                'product_name' => 'iPhone 15 Pro',
                'warranty_code' => str_repeat('A', 51), // 51 caractères (max 50)
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

        $result = $this->service->validate($salesData, []);

        $this->assertCount(0, $result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Warranty code too long', $result['errors'][0]['error_reason']);
    }

    public function testValidateWarrantyCodeNonAlphanumeric(): void
    {
        $salesData = [
            [
                'partner_name' => 'Fnac',
                'customer_email' => 'jean.dupont@email.com',
                'product_name' => 'iPhone 15 Pro',
                'warranty_code' => 'EXT-2Y', // Contient un tiret (non alphanumérique)
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

        $result = $this->service->validate($salesData, []);

        $this->assertCount(0, $result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('must be alphanumeric', $result['errors'][0]['error_reason']);
    }

    public function testValidatePurchaseDateAfterWarrantyPurchaseDate(): void
    {
        $salesData = [
            [
                'partner_name' => 'Fnac',
                'customer_email' => 'jean.dupont@email.com',
                'product_name' => 'iPhone 15 Pro',
                'warranty_code' => 'EXT2Y',
                'warranty_label' => 'Extension Garantie 2 ans',
                'warranty_start_date' => '2024-01-20',
                'warranty_end_date' => '2026-01-20',
                'product_purchase_price' => '1199.99',
                'warranty_purchase_date' => '2024-01-15', // Avant purchase_date
                'invoice_number' => 'INV-2024-001',
                'purchase_date' => '2024-01-20', // Après warranty_purchase_date (invalide)
                'customer_address_street' => '12 rue de la Paix',
                'customer_address_city' => 'Paris',
                'customer_address_zipcode' => '75002',
                'customer_address_country' => 'France',
            ],
        ];

        $result = $this->service->validate($salesData, []);

        $this->assertCount(0, $result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('purchase_date', $result['errors'][0]['error_reason']);
        $this->assertStringContainsString('warranty_purchase_date', $result['errors'][0]['error_reason']);
    }

    public function testValidateWarrantyPurchaseDateAfterWarrantyStartDate(): void
    {
        $salesData = [
            [
                'partner_name' => 'Fnac',
                'customer_email' => 'jean.dupont@email.com',
                'product_name' => 'iPhone 15 Pro',
                'warranty_code' => 'EXT2Y',
                'warranty_label' => 'Extension Garantie 2 ans',
                'warranty_start_date' => '2024-01-15',
                'warranty_end_date' => '2026-01-15',
                'product_purchase_price' => '1199.99',
                'warranty_purchase_date' => '2024-01-20', // Après warranty_start_date (invalide)
                'invoice_number' => 'INV-2024-001',
                'purchase_date' => '2024-01-15',
                'customer_address_street' => '12 rue de la Paix',
                'customer_address_city' => 'Paris',
                'customer_address_zipcode' => '75002',
                'customer_address_country' => 'France',
            ],
        ];

        $result = $this->service->validate($salesData, []);

        $this->assertCount(0, $result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('warranty_purchase_date', $result['errors'][0]['error_reason']);
        $this->assertStringContainsString('warranty_start_date', $result['errors'][0]['error_reason']);
    }

    public function testValidateMultipleErrors(): void
    {
        $salesData = [
            [
                'partner_name' => 'Fnac',
                'customer_email' => 'invalid-email', // Email invalide
                'product_name' => 'iPhone 15 Pro',
                'warranty_code' => 'EXT-2Y', // Non alphanumérique
                'warranty_label' => 'Extension Garantie 2 ans',
                'warranty_start_date' => '2024-01-15',
                'warranty_end_date' => '2023-01-15', // Avant warranty_start_date
                'product_purchase_price' => '-100', // Prix négatif
                'warranty_purchase_date' => '2024-01-15',
                'invoice_number' => 'INV-2024-001',
                'purchase_date' => '2024-01-15',
                'customer_address_street' => '12 rue de la Paix',
                'customer_address_city' => 'Paris',
                'customer_address_zipcode' => '75002',
                'customer_address_country' => 'France',
            ],
        ];

        $result = $this->service->validate($salesData, []);

        $this->assertCount(0, $result['valid']);
        $this->assertCount(1, $result['errors']);

        // Vérifier que plusieurs erreurs sont présentes
        // Note: La validation des dates ne se fait QUE si le format est valide
        // et qu'il n'y a pas d'autres erreurs avant (prix, email, etc.)
        $errorReason = $result['errors'][0]['error_reason'];
        $this->assertStringContainsString('Invalid email', $errorReason);
        $this->assertStringContainsString('Invalid price', $errorReason);
        $this->assertStringContainsString('alphanumeric', $errorReason);

        // Compter le nombre d'erreurs séparées par "; "
        $errors = explode('; ', $errorReason);
        $this->assertGreaterThanOrEqual(3, count($errors), 'Should have at least 3 errors');
    }

    public function testValidateMixedValidAndInvalidData(): void
    {
        $salesData = [
            // Ligne valide
            [
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
            // Ligne invalide
            [
                'partner_name' => 'Darty',
                'customer_email' => 'invalid-email',
                'product_name' => 'MacBook',
                'warranty_code' => 'APPC3Y',
                'warranty_label' => 'AppleCare+ 3 ans',
                'warranty_start_date' => '2024-02-01',
                'warranty_end_date' => '2027-02-01',
                'product_purchase_price' => '1499.00',
                'warranty_purchase_date' => '2024-02-01',
                'invoice_number' => 'INV-2024-002',
                'purchase_date' => '2024-02-01',
                'customer_address_street' => '45 avenue des Champs',
                'customer_address_city' => 'Lyon',
                'customer_address_zipcode' => '69001',
                'customer_address_country' => 'France',
            ],
            // Ligne valide
            [
                'partner_name' => 'Boulanger',
                'customer_email' => 'pierre.martin@email.com',
                'product_name' => 'iPad Pro',
                'warranty_code' => 'APPC2Y',
                'warranty_label' => 'AppleCare+ 2 ans',
                'warranty_start_date' => '2024-03-01',
                'warranty_end_date' => '2026-03-01',
                'product_purchase_price' => '899.00',
                'warranty_purchase_date' => '2024-03-01',
                'invoice_number' => 'INV-2024-003',
                'purchase_date' => '2024-03-01',
                'customer_address_street' => '10 boulevard Victor Hugo',
                'customer_address_city' => 'Marseille',
                'customer_address_zipcode' => '13001',
                'customer_address_country' => 'France',
            ],
        ];

        $result = $this->service->validate($salesData, []);

        $this->assertCount(2, $result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertEquals('jean.dupont@email.com', $result['valid'][0]['customer_email']);
        $this->assertEquals('pierre.martin@email.com', $result['valid'][1]['customer_email']);
        $this->assertEquals('invalid-email', $result['errors'][0]['customer_email']);
    }
}
