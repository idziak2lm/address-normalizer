<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Address Normalizer API',
    description: <<<'DESC'
## Overview

Address Normalizer is a REST API for cleaning and normalizing messy postal addresses from European countries.
It uses an AI pipeline (OpenAI / Anthropic) combined with regex pre-parsing, postal code validation,
and optional Google Address Validation for geocoding.

## How it works

Each address goes through the following pipeline:

1. **Pre-cleaning** — regex removes phone numbers, emails, emojis, excessive whitespace
2. **Cache lookup** — Redis cache (30-day TTL); if hit, returns immediately
3. **Street parsing** — country-specific regex extracts street, house number, apartment
4. **AI normalization** — LLM (GPT-4o-mini or Claude Sonnet) parses the full address, extracts company names, identifies noise
5. **Post-validation** — validates postal code format against country, cross-checks with regex, adjusts confidence
6. **Google validation** *(optional)* — verifies address via Google Address Validation API, returns coordinates and quality flags
7. **Cache store** — result is cached for future lookups

## Authentication

All endpoints require a **Bearer token** in the `Authorization` header:

```
Authorization: Bearer your_api_token_here
```

Tokens are issued per client. Contact the administrator to obtain one.

## Rate limits

Each client has a **monthly request limit**. When exceeded, the API returns `429 Too Many Requests`.
Check your current usage via the `GET /api/v1/status` endpoint.

For batch requests, each address in the batch counts as one request toward your limit.

## Supported countries

The API supports **31 European countries**: PL, CZ, SK, DE, AT, CH, FR, IT, ES, PT, NL, BE, LU, GB, IE,
DK, SE, NO, FI, HU, RO, BG, HR, SI, GR, CY, MT, LT, LV, EE, UA.

## Confidence score

Every result includes a `confidence` score (0.0–1.0):

| Range | Meaning |
|-------|---------|
| **0.9–1.0** | Address is clear and unambiguous |
| **0.7–0.89** | High confidence, minor uncertainties |
| **0.5–0.69** | Moderate confidence, some data may be missing or ambiguous |
| **< 0.5** | Low confidence — review manually |

The score is determined by the AI and then adjusted by post-validation checks
(postal code format, regex cross-check, Google validation).

## Source field

The `source` field indicates how the result was obtained:

- `cache` — returned from Redis cache (no AI call, fastest)
- `ai` — normalized by AI provider (OpenAI or Anthropic)
- `libpostal+ai` — pre-parsed by Libpostal, then normalized by AI

## Google validation

When enabled, results include a `google_validation` object with:
- **Coordinates** (`latitude`, `longitude`) — geographic location
- **Validation granularity** — how precisely Google matched the address (`SUB_PREMISE` = best, `OTHER` = worst)
- **Quality flags** — `address_complete`, `has_unconfirmed_components`, `has_inferred_components`, etc.
- **Property type** — `is_residential`, `is_business`
- **Issues** — human-readable list of problems found

## CSV batch upload

For bulk processing (up to 10,000 addresses), use the web interface at `/csv`.
Upload a semicolon-separated CSV file and download results when processing completes.

## Error codes

| Code | Meaning |
|------|---------|
| `200` | Success |
| `401` | Missing or invalid Bearer token |
| `422` | Validation error (missing/invalid fields) |
| `429` | Monthly usage limit exceeded |
| `503` | All AI providers unavailable |
DESC,
    contact: new OA\Contact(name: 'Address Normalizer Support', url: 'https://postalcodes.lumengroup.eu'),
)]
#[OA\Server(url: 'https://postalcodes.lumengroup.eu', description: 'Production')]
#[OA\Server(url: 'http://localhost:8889', description: 'Local development')]
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
