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
        new OA\Property(property: 'street', type: 'string', description: 'Street name (ul. prefix removed; al./pl./os. preserved)', example: 'Marszałkowska', nullable: true),
        new OA\Property(property: 'house_number', type: 'string', example: '1', nullable: true),
        new OA\Property(property: 'apartment_number', type: 'string', example: '2', nullable: true),
        new OA\Property(property: 'company_name', type: 'string', description: 'Company name extracted from other fields', example: 'FHU Jan Kowalski', nullable: true),
        new OA\Property(property: 'formatted', type: 'string', description: 'Human-readable formatted address', example: 'Marszałkowska 1/2, 00-001 Warszawa'),
        new OA\Property(property: 'google_validation', ref: '#/components/schemas/GoogleValidationData', description: 'Google Address Validation results (when enabled)', nullable: true),
    ],
)]
#[OA\Schema(
    schema: 'GoogleValidationData',
    title: 'Google Validation Result',
    description: 'Address validation and geocoding data from Google Address Validation API. Only present when Google Address Validation is enabled on the server.',
    properties: [
        new OA\Property(property: 'latitude', type: 'number', format: 'double', description: 'Geographic latitude', example: 52.2297, nullable: true),
        new OA\Property(property: 'longitude', type: 'number', format: 'double', description: 'Geographic longitude', example: 21.0122, nullable: true),
        new OA\Property(property: 'place_id', type: 'string', description: 'Google Place ID — can be used with Google Maps/Places API', example: 'ChIJAZ-GmmbMHkcR_NPqiCq-8HI', nullable: true),
        new OA\Property(property: 'validation_granularity', type: 'string', description: 'How precisely Google matched the address. SUB_PREMISE (apartment level) is the most precise, OTHER means no match.', enum: ['OTHER', 'ROUTE', 'BLOCK', 'PREMISE_PROXIMITY', 'PREMISE', 'SUB_PREMISE'], example: 'PREMISE'),
        new OA\Property(property: 'geocode_granularity', type: 'string', description: 'Precision level of the geographic coordinates', enum: ['OTHER', 'ROUTE', 'BLOCK', 'PREMISE_PROXIMITY', 'PREMISE', 'SUB_PREMISE'], example: 'PREMISE'),
        new OA\Property(property: 'address_complete', type: 'boolean', description: 'All address components are present and confirmed by Google', example: true),
        new OA\Property(property: 'has_unconfirmed_components', type: 'boolean', description: 'Some components could not be confirmed — may need manual review', example: false),
        new OA\Property(property: 'has_inferred_components', type: 'boolean', description: 'Google filled in missing data (e.g. inferred postal code from city)', example: false),
        new OA\Property(property: 'has_replaced_components', type: 'boolean', description: 'Google corrected/replaced some components (e.g. wrong postal code)', example: false),
        new OA\Property(property: 'has_spell_corrected_components', type: 'boolean', description: 'Spelling corrections were applied to the address', example: false),
        new OA\Property(property: 'is_residential', type: 'boolean', description: 'Address is classified as residential', example: true, nullable: true),
        new OA\Property(property: 'is_business', type: 'boolean', description: 'Address is classified as a business location', example: false, nullable: true),
        new OA\Property(property: 'issues', type: 'array', description: 'Human-readable list of validation issues found (empty = no issues)', items: new OA\Items(type: 'string'), example: []),
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
