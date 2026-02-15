<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class SalesImportControllerTest extends WebTestCase
{
    public function testImportWithoutFile(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/salesforce/import-sales');

        $this->assertResponseStatusCodeSame(400);

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        $this->assertEquals('error', $data['status']);
        $this->assertStringContainsString('No file uploaded', $data['error']);
    }

    public function testImportWithInvalidExtension(): void
    {
        $client = static::createClient();

        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tempFile, 'test content');

        $file = new UploadedFile(
            $tempFile,
            'test.txt',
            'text/plain',
            null,
            true
        );

        $client->request('POST', '/api/salesforce/import-sales', [], ['file' => $file]);

        $this->assertResponseStatusCodeSame(400);

        $response = $client->getResponse();
        $data = json_decode($response->getContent(), true);

        $this->assertEquals('error', $data['status']);
        $this->assertStringContainsString('Invalid file extension', $data['error']);
    }

    public function testImportEndpointExists(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/salesforce/import-sales');

        // La route existe mais n'accepte que POST
        $this->assertResponseStatusCodeSame(405);
    }
}
