<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Address Normalizer API',
    description: 'REST API for normalizing and cleaning European postal addresses using AI (OpenAI / Anthropic).',
    contact: new OA\Contact(name: 'LumenGroup', url: 'https://postalcodes.lumengroup.eu'),
)]
#[OA\Server(url: 'http://localhost:8889', description: 'Local Docker')]
#[OA\Server(url: 'https://postalcodes.lumengroup.eu', description: 'Production')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    description: 'API Bearer token. Obtain via `php artisan api-client:create`.',
    scheme: 'bearer',
)]
abstract class Controller
{
    //
}
