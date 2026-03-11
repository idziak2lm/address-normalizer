<?php

namespace Tests\Unit;

use App\DTOs\GoogleValidationResult;
use App\DTOs\NormalizedAddress;
use App\Services\GoogleAddressValidationClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAddressValidationClientTest extends TestCase
{
    private GoogleAddressValidationClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new GoogleAddressValidationClient;

        config([
            'normalizer.google_validation.enabled' => true,
            'normalizer.google_validation.api_key' => 'test-google-key',
            'normalizer.google_validation.timeout' => 5,
        ]);
    }

    public function test_is_disabled_when_not_configured(): void
    {
        config(['normalizer.google_validation.enabled' => false]);

        $this->assertFalse($this->client->isEnabled());
    }

    public function test_is_disabled_when_api_key_missing(): void
    {
        config(['normalizer.google_validation.api_key' => null]);

        $this->assertFalse($this->client->isEnabled());
    }

    public function test_is_enabled_when_configured(): void
    {
        $this->assertTrue($this->client->isEnabled());
    }

    public function test_returns_null_when_disabled(): void
    {
        config(['normalizer.google_validation.enabled' => false]);

        $address = $this->makeAddress();

        $this->assertNull($this->client->validate($address));
    }

    public function test_validates_address_successfully(): void
    {
        Http::fake([
            'addressvalidation.googleapis.com/*' => Http::response([
                'result' => $this->sampleApiResponse(),
            ]),
        ]);

        $result = $this->client->validate($this->makeAddress());

        $this->assertNotNull($result);
        $this->assertInstanceOf(GoogleValidationResult::class, $result);
        $this->assertEquals(52.2297, $result->latitude);
        $this->assertEquals(21.0122, $result->longitude);
        $this->assertEquals('PREMISE', $result->validationGranularity);
        $this->assertTrue($result->addressComplete);
        $this->assertFalse($result->hasUnconfirmedComponents);
        $this->assertEquals('ChIJAZ-GmmbMHkcR_NPqiCq-8HI', $result->placeId);
    }

    public function test_returns_null_on_api_error(): void
    {
        Http::fake([
            'addressvalidation.googleapis.com/*' => Http::response(['error' => 'bad request'], 400),
        ]);

        $result = $this->client->validate($this->makeAddress());

        $this->assertNull($result);
    }

    public function test_returns_null_on_exception(): void
    {
        Http::fake([
            'addressvalidation.googleapis.com/*' => fn () => throw new \RuntimeException('Connection failed'),
        ]);

        $result = $this->client->validate($this->makeAddress());

        $this->assertNull($result);
    }

    public function test_applies_corrections_with_confidence_boost(): void
    {
        $address = $this->makeAddress(confidence: 0.85);

        $validation = GoogleValidationResult::fromApiResponse($this->sampleApiResponse());

        $corrected = $this->client->applyCorrections($address, $validation);

        $this->assertNotNull($corrected->google_validation);
        $this->assertEquals(52.2297, $corrected->google_validation->latitude);
        $this->assertEquals(21.0122, $corrected->google_validation->longitude);
        // PREMISE (+0.05) + addressComplete (+0.05) = +0.10
        $this->assertEquals(0.95, $corrected->confidence);
    }

    public function test_applies_corrected_postal_code(): void
    {
        $address = $this->makeAddress(postal_code: '00-000');

        $response = $this->sampleApiResponse();
        $response['address']['addressComponents'][] = [
            'componentName' => ['text' => '00-001'],
            'componentType' => 'postal_code',
            'replaced' => true,
        ];

        $validation = GoogleValidationResult::fromApiResponse($response);

        $corrected = $this->client->applyCorrections($address, $validation);

        $this->assertEquals('00-001', $corrected->postal_code);
    }

    public function test_applies_corrected_city_name(): void
    {
        $address = $this->makeAddress(city: 'Varszawa');

        $response = $this->sampleApiResponse();
        $response['address']['addressComponents'][] = [
            'componentName' => ['text' => 'Warszawa'],
            'componentType' => 'locality',
            'spellCorrected' => true,
        ];

        $validation = GoogleValidationResult::fromApiResponse($response);

        $corrected = $this->client->applyCorrections($address, $validation);

        $this->assertEquals('Warszawa', $corrected->city);
    }

    public function test_confidence_penalty_for_low_granularity(): void
    {
        $address = $this->makeAddress(confidence: 0.90);

        $response = $this->sampleApiResponse();
        $response['verdict']['validationGranularity'] = 'OTHER';
        $response['verdict']['addressComplete'] = false;
        $response['verdict']['hasUnconfirmedComponents'] = true;

        $validation = GoogleValidationResult::fromApiResponse($response);

        $corrected = $this->client->applyCorrections($address, $validation);

        // OTHER (-0.25) + !addressComplete (0) + unconfirmed (-0.10) = -0.35
        $this->assertEquals(0.55, $corrected->confidence);
    }

    public function test_confidence_does_not_exceed_bounds(): void
    {
        $address = $this->makeAddress(confidence: 0.98);

        $validation = GoogleValidationResult::fromApiResponse($this->sampleApiResponse());

        $corrected = $this->client->applyCorrections($address, $validation);

        // 0.98 + 0.10 = 1.08, clamped to 1.0
        $this->assertEquals(1.0, $corrected->confidence);
    }

    public function test_sends_correct_payload_to_google(): void
    {
        Http::fake([
            'addressvalidation.googleapis.com/*' => Http::response([
                'result' => $this->sampleApiResponse(),
            ]),
        ]);

        $address = $this->makeAddress();
        $this->client->validate($address);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), 'key=test-google-key')
                && $body['address']['regionCode'] === 'PL'
                && $body['address']['locality'] === 'Warszawa'
                && $body['address']['postalCode'] === '00-001'
                && $body['address']['addressLines'] === ['Marszałkowska 1/2'];
        });
    }

    public function test_google_validation_result_issues(): void
    {
        $response = $this->sampleApiResponse();
        $response['verdict']['addressComplete'] = false;
        $response['verdict']['hasUnconfirmedComponents'] = true;
        $response['verdict']['hasSpellCorrectedComponents'] = true;
        $response['address']['unconfirmedComponentTypes'] = ['street_number'];
        $response['address']['missingComponentTypes'] = ['subpremise'];

        $validation = GoogleValidationResult::fromApiResponse($response);

        $issues = $validation->issues();

        $this->assertNotEmpty($issues);
        $this->assertContains('Address is incomplete', $issues);
        $this->assertContains('Missing components: subpremise', $issues);
        $this->assertContains('Spelling corrections were applied', $issues);
    }

    public function test_google_validation_result_to_array(): void
    {
        $validation = GoogleValidationResult::fromApiResponse($this->sampleApiResponse());

        $array = $validation->toArray();

        $this->assertEquals(52.2297, $array['latitude']);
        $this->assertEquals(21.0122, $array['longitude']);
        $this->assertEquals('PREMISE', $array['validation_granularity']);
        $this->assertTrue($array['address_complete']);
        $this->assertIsArray($array['issues']);
    }

    private function makeAddress(
        string $postal_code = '00-001',
        string $city = 'Warszawa',
        float $confidence = 0.90,
    ): NormalizedAddress {
        return new NormalizedAddress(
            country_code: 'PL',
            region: 'mazowieckie',
            postal_code: $postal_code,
            city: $city,
            street: 'Marszałkowska',
            house_number: '1',
            apartment_number: '2',
            company_name: null,
            formatted: 'Marszałkowska 1/2, 00-001 Warszawa',
            removed_noise: [],
            confidence: $confidence,
        );
    }

    private function sampleApiResponse(): array
    {
        return [
            'verdict' => [
                'validationGranularity' => 'PREMISE',
                'geocodeGranularity' => 'PREMISE',
                'addressComplete' => true,
                'hasUnconfirmedComponents' => false,
                'hasInferredComponents' => false,
                'hasReplacedComponents' => false,
                'hasSpellCorrectedComponents' => false,
            ],
            'address' => [
                'formattedAddress' => 'Marszałkowska 1/2, 00-001 Warszawa, Poland',
                'postalAddress' => [
                    'regionCode' => 'PL',
                    'postalCode' => '00-001',
                    'locality' => 'Warszawa',
                    'addressLines' => ['Marszałkowska 1/2'],
                ],
                'addressComponents' => [],
                'missingComponentTypes' => [],
                'unconfirmedComponentTypes' => [],
                'unresolvedTokens' => [],
            ],
            'geocode' => [
                'location' => [
                    'latitude' => 52.2297,
                    'longitude' => 21.0122,
                ],
                'placeId' => 'ChIJAZ-GmmbMHkcR_NPqiCq-8HI',
            ],
            'metadata' => [
                'residential' => true,
                'business' => false,
            ],
        ];
    }
}
