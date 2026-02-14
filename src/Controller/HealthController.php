<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    #[Route('/', name: 'health_check', methods: ['GET'])]
    public function check(): JsonResponse
    {
        return $this->json([
            'status' => 'ok',
            'application' => 'SF API Import Salesforce',
            'version' => '1.0.0',
            'environment' => $this->getParameter('kernel.environment'),
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }
}
