<?php

namespace Tests\Unit;

use App\DTOs\RawAddressInput;
use App\Exceptions\NormalizationException;
use App\Services\LlmProviders\OpenAiProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'normalizer.openai.api_key' => 'test-key',
            'normalizer.openai.model' => 'gpt-4o-mini',
            'normalizer.openai.timeout' => 10,
            'normalizer.openai.max_retries' => 0, // No retries in tests
        ]);
    }

    public function test_normalize_single_address(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'country_code' => 'PL',
                                'region' => 'mazowieckie',
                                'postal_code' => '00-001',
                                'city' => 'Warszawa',
                                'street' => 'Marszałkowska',
                                'house_number' => '1',
                                'apartment_number' => '2',
                                'company_name' => null,
                                'removed_noise' => [],
                                'confidence' => 0.95,
                                'formatted' => 'Marszałkowska 1/2, 00-001 Warszawa',
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $provider = new OpenAiProvider;
        $input = new RawAddressInput(
            country: 'PL',
            city: 'Warszawa',
            address: 'Marszałkowska 1/2',
            postal_code: '00-001',
        );

        $result = $provider->normalize($input);

        $this->assertEquals('PL', $result->country_code);
        $this->assertEquals('Warszawa', $result->city);
        $this->assertEquals('Marszałkowska', $result->street);
        $this->assertEquals('1', $result->house_number);
        $this->assertEquals('2', $result->apartment_number);
        $this->assertEquals(0.95, $result->confidence);

        Http::assertSentCount(1);
    }

    public function test_normalize_batch(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                [
                                    'country_code' => 'PL',
                                    'region' => null,
                                    'postal_code' => '00-001',
                                    'city' => 'Warszawa',
                                    'street' => 'Marszałkowska',
                                    'house_number' => '1',
                                    'apartment_number' => null,
                                    'company_name' => null,
                                    'removed_noise' => [],
                                    'confidence' => 0.95,
                                    'formatted' => 'Marszałkowska 1, 00-001 Warszawa',
                                ],
                                [
                                    'country_code' => 'DE',
                                    'region' => null,
                                    'postal_code' => '10115',
                                    'city' => 'Berlin',
                                    'street' => 'Friedrichstraße',
                                    'house_number' => '123',
                                    'apartment_number' => null,
                                    'company_name' => null,
                                    'removed_noise' => [],
                                    'confidence' => 0.98,
                                    'formatted' => 'Friedrichstraße 123, 10115 Berlin',
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $provider = new OpenAiProvider;
        $inputs = [
            new RawAddressInput(country: 'PL', city: 'Warszawa', address: 'Marszałkowska 1', postal_code: '00-001'),
            new RawAddressInput(country: 'DE', city: 'Berlin', address: 'Friedrichstraße 123', postal_code: '10115'),
        ];

        $results = $provider->normalizeBatch($inputs);

        $this->assertCount(2, $results);
        $this->assertEquals('PL', $results[0]->country_code);
        $this->assertEquals('DE', $results[1]->country_code);
    }

    public function test_throws_on_api_failure(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $provider = new OpenAiProvider;
        $input = new RawAddressInput(
            country: 'PL',
            city: 'Warszawa',
            address: 'Marszałkowska 1',
        );

        $this->expectException(NormalizationException::class);
        $provider->normalize($input);
    }

    public function test_provider_name(): void
    {
        $provider = new OpenAiProvider;
        $this->assertEquals('openai', $provider->name());
    }
}
