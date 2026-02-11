<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'NormalizedAddressData',
    title: 'Normalized Address',
    description: 'Structured normalized address data',
    required: ['country_code', 'city', 'formatted'],
    properties: [
        new OA\Property(property: 'country_code', type: 'string', description: 'ISO 3166-1 alpha-2', example: 'PL'),
        new OA\Property(property: 'region', type: 'string', description: 'Region/voivodeship/Bundesland', example: 'mazowieckie', nullable: true),
        new OA\Property(property: 'postal_code', type: 'string', example: '00-001', nullable: true),
        new OA\Property(property: 'city', type: 'string', example: 'Warszawa'),
        new OA\Property(property: 'street', type: 'string', description: 'Street name without prefix (ul., al.)', example: 'Marszałkowska', nullable: true),
        new OA\Property(property: 'house_number', type: 'string', example: '1', nullable: true),
        new OA\Property(property: 'apartment_number', type: 'string', example: '2', nullable: true),
        new OA\Property(property: 'company_name', type: 'string', description: 'Company name extracted from other fields', example: 'FHU Jan Kowalski', nullable: true),
        new OA\Property(property: 'formatted', type: 'string', description: 'Human-readable formatted address', example: 'Marszałkowska 1/2, 00-001 Warszawa'),
    ],
)]
#[OA\Response(
    response: 'Unauthorized',
    description: 'Unauthorized — missing or invalid Bearer token',
    content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.'),
        ],
    ),
)]
#[OA\Response(
    response: 'RateLimitExceeded',
    description: 'Monthly usage limit exceeded',
    content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'status', type: 'string', example: 'error'),
            new OA\Property(property: 'message', type: 'string', example: 'Monthly usage limit exceeded.'),
            new OA\Property(property: 'limit', type: 'integer', example: 10000),
            new OA\Property(property: 'used', type: 'integer', example: 10000),
        ],
    ),
)]
#[OA\Response(
    response: 'ProvidersUnavailable',
    description: 'All AI providers are currently unavailable',
    content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'status', type: 'string', example: 'error'),
            new OA\Property(property: 'message', type: 'string', example: 'All AI providers are currently unavailable. Please try again later.'),
        ],
    ),
)]
class Schemas
{
    // This class exists solely to hold OpenAPI schema/response attributes.
}
