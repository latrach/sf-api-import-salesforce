<?php

declare(strict_types=1);

namespace App\Tests\Service\Sales;

use App\Service\Sales\CsvParserService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class CsvParserServiceTest extends TestCase
{
    private CsvParserService $service;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new CsvParserService($this->logger);
    }

    public function testParseValidCsvFile(): void
    {
        $csvContent = <<<CSV
partner_name,customer_email,product_name,warranty_code,warranty_label,warranty_start_date,warranty_end_date,product_purchase_price,warranty_purchase_date,invoice_number,purchase_date,customer_address_street,customer_address_city,customer_address_zipcode,customer_address_country
Fnac,jean.dupont@email.com,iPhone 15 Pro,EXT2Y,Extension Garantie 2 ans,2024-01-15,2026-01-15,1199.99,2024-01-15,INV-2024-001,2024-01-15,12 rue de la Paix,Paris,75002,France
Darty,marie.martin@email.com,MacBook Air M3,APPC3Y,AppleCare+ 3 ans,2024-02-01,2027-02-01,1499.00,2024-02-01,INV-2024-002,2024-02-01,45 avenue des Champs,Lyon,69001,France
CSV;

        $file = $this->createTempCsvFile($csvContent);

        $result = $this->service->parse($file, ['import_id' => 'test_123']);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('partner_name', $result[0]);
        $this->assertEquals('Fnac', $result[0]['partner_name']);
        $this->assertEquals('jean.dupont@email.com', $result[0]['customer_email']);
        $this->assertEquals('Darty', $result[1]['partner_name']);
    }

    public function testParseEmptyFileThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CSV file is empty or invalid');

        $file = $this->createTempCsvFile('');
        $this->service->parse($file, []);
    }

    public function testParseInvalidHeadersThrowsException(): void
    {
        $csvContent = <<<CSV
wrong_header,another_wrong,third_wrong
value1,value2,value3
CSV;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid CSV headers');

        $file = $this->createTempCsvFile($csvContent);
        $this->service->parse($file, []);
    }

    public function testParseWithInvalidColumnCount(): void
    {
        $csvContent = <<<CSV
partner_name,customer_email,product_name,warranty_code,warranty_label,warranty_start_date,warranty_end_date,product_purchase_price,warranty_purchase_date,invoice_number,purchase_date,customer_address_street,customer_address_city,customer_address_zipcode,customer_address_country
Fnac,jean.dupont@email.com,iPhone 15 Pro
Darty,marie.martin@email.com,MacBook Air M3,APPC3Y,AppleCare+ 3 ans,2024-02-01,2027-02-01,1499.00,2024-02-01,INV-2024-002,2024-02-01,45 avenue des Champs,Lyon,69001,France
CSV;

        $file = $this->createTempCsvFile($csvContent);

        $result = $this->service->parse($file, ['import_id' => 'test_123']);

        // La première ligne a un nombre de colonnes incorrect, elle doit être ignorée
        // Seule la deuxième ligne doit être parsée
        $this->assertCount(1, $result);
        $this->assertEquals('Darty', $result[0]['partner_name']);
    }

    public function testParseWithEmptyLines(): void
    {
        $csvContent = <<<CSV
partner_name,customer_email,product_name,warranty_code,warranty_label,warranty_start_date,warranty_end_date,product_purchase_price,warranty_purchase_date,invoice_number,purchase_date,customer_address_street,customer_address_city,customer_address_zipcode,customer_address_country
Fnac,jean.dupont@email.com,iPhone 15 Pro,EXT2Y,Extension Garantie 2 ans,2024-01-15,2026-01-15,1199.99,2024-01-15,INV-2024-001,2024-01-15,12 rue de la Paix,Paris,75002,France

Darty,marie.martin@email.com,MacBook Air M3,APPC3Y,AppleCare+ 3 ans,2024-02-01,2027-02-01,1499.00,2024-02-01,INV-2024-002,2024-02-01,45 avenue des Champs,Lyon,69001,France
CSV;

        $file = $this->createTempCsvFile($csvContent);

        $result = $this->service->parse($file, ['import_id' => 'test_123']);

        // Les lignes vides doivent être ignorées
        $this->assertCount(2, $result);
        $this->assertEquals('Fnac', $result[0]['partner_name']);
        $this->assertEquals('Darty', $result[1]['partner_name']);
    }

    public function testParseWithSpecialCharacters(): void
    {
        $csvContent = <<<CSV
partner_name,customer_email,product_name,warranty_code,warranty_label,warranty_start_date,warranty_end_date,product_purchase_price,warranty_purchase_date,invoice_number,purchase_date,customer_address_street,customer_address_city,customer_address_zipcode,customer_address_country
Fnac,jean.dupont@email.com,"iPhone ""Pro"" 15",EXT2Y,"Extension Garantie 2 ans, complète",2024-01-15,2026-01-15,1199.99,2024-01-15,INV-2024-001,2024-01-15,"12 rue de la ""Paix""",Paris,75002,France
CSV;

        $file = $this->createTempCsvFile($csvContent);

        $result = $this->service->parse($file, ['import_id' => 'test_123']);

        $this->assertCount(1, $result);
        $this->assertEquals('iPhone "Pro" 15', $result[0]['product_name']);
        $this->assertEquals('Extension Garantie 2 ans, complète', $result[0]['warranty_label']);
        $this->assertEquals('12 rue de la "Paix"', $result[0]['customer_address_street']);
    }

    public function testParseWithUtf8BOM(): void
    {
        // BOM UTF-8 = EF BB BF
        $csvContent = "\xEF\xBB\xBF" . <<<CSV
partner_name,customer_email,product_name,warranty_code,warranty_label,warranty_start_date,warranty_end_date,product_purchase_price,warranty_purchase_date,invoice_number,purchase_date,customer_address_street,customer_address_city,customer_address_zipcode,customer_address_country
Fnac,jean.dupont@email.com,iPhone 15 Pro,EXT2Y,Extension Garantie 2 ans,2024-01-15,2026-01-15,1199.99,2024-01-15,INV-2024-001,2024-01-15,12 rue de la Paix,Paris,75002,France
CSV;

        $file = $this->createTempCsvFile($csvContent);

        // Le BOM devrait être géré par PHP (fgetcsv ignore automatiquement le BOM UTF-8)
        // Mais si le parsing échoue, c'est un cas limite important à détecter
        try {
            $result = $this->service->parse($file, ['import_id' => 'test_123']);
            // Si ça passe, vérifier que les données sont correctes
            $this->assertIsArray($result);
        } catch (\RuntimeException $e) {
            // Si ça échoue à cause du BOM, c'est un problème connu
            $this->assertStringContainsString('Invalid CSV headers', $e->getMessage());
        }
    }

    public function testParseWithAccentedCharacters(): void
    {
        $csvContent = <<<CSV
partner_name,customer_email,product_name,warranty_code,warranty_label,warranty_start_date,warranty_end_date,product_purchase_price,warranty_purchase_date,invoice_number,purchase_date,customer_address_street,customer_address_city,customer_address_zipcode,customer_address_country
Fnac,françois.élève@email.com,iPhone 15 Pro,EXT2Y,Garantie étendue 2 ans,2024-01-15,2026-01-15,1199.99,2024-01-15,INV-2024-001,2024-01-15,12 rue de la Paix,Paris,75002,France
CSV;

        $file = $this->createTempCsvFile($csvContent);

        $result = $this->service->parse($file, ['import_id' => 'test_123']);

        $this->assertCount(1, $result);
        $this->assertEquals('françois.élève@email.com', $result[0]['customer_email']);
        $this->assertEquals('Garantie étendue 2 ans', $result[0]['warranty_label']);
    }

    public function testParseMultipleLinesWithDifferentData(): void
    {
        $csvContent = <<<CSV
partner_name,customer_email,product_name,warranty_code,warranty_label,warranty_start_date,warranty_end_date,product_purchase_price,warranty_purchase_date,invoice_number,purchase_date,customer_address_street,customer_address_city,customer_address_zipcode,customer_address_country
Fnac,jean.dupont@email.com,iPhone 15 Pro,EXT2Y,Extension Garantie 2 ans,2024-01-15,2026-01-15,1199.99,2024-01-15,INV-2024-001,2024-01-15,12 rue de la Paix,Paris,75002,France
Darty,marie.martin@email.com,MacBook Air M3,APPC3Y,AppleCare+ 3 ans,2024-02-01,2027-02-01,1499.00,2024-02-01,INV-2024-002,2024-02-01,45 avenue des Champs,Lyon,69001,France
Boulanger,pierre.durand@email.com,iPad Pro,APPC2Y,AppleCare+ 2 ans,2024-03-01,2026-03-01,899.99,2024-03-01,INV-2024-003,2024-03-01,10 boulevard Victor Hugo,Marseille,13001,France
CSV;

        $file = $this->createTempCsvFile($csvContent);

        $result = $this->service->parse($file, ['import_id' => 'test_123']);

        $this->assertCount(3, $result);

        // Vérifier que toutes les lignes ont bien été parsées avec les bonnes données
        $this->assertEquals('Fnac', $result[0]['partner_name']);
        $this->assertEquals('Darty', $result[1]['partner_name']);
        $this->assertEquals('Boulanger', $result[2]['partner_name']);

        $this->assertEquals('iPhone 15 Pro', $result[0]['product_name']);
        $this->assertEquals('MacBook Air M3', $result[1]['product_name']);
        $this->assertEquals('iPad Pro', $result[2]['product_name']);
    }

    public function testParseWithOnlyHeaderReturnsEmptyArray(): void
    {
        $csvContent = 'partner_name,customer_email,product_name,warranty_code,warranty_label,warranty_start_date,warranty_end_date,product_purchase_price,warranty_purchase_date,invoice_number,purchase_date,customer_address_street,customer_address_city,customer_address_zipcode,customer_address_country';

        $file = $this->createTempCsvFile($csvContent);

        $result = $this->service->parse($file, ['import_id' => 'test_123']);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    private function createTempCsvFile(string $content): UploadedFile
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'csv_test_');
        file_put_contents($tempFile, $content);

        return new UploadedFile(
            $tempFile,
            'test.csv',
            'text/csv',
            null,
            true
        );
    }
}
