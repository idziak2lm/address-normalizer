<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BatchEndpointTest extends TestCase
{
    use RefreshDatabase;

    private ApiClient $client;

    private string $plainToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plainToken = 'test-token-batch';
        $this->client = ApiClient::create([
            'name' => 'batch-client',
            'api_key' => hash('sha256', $this->plainToken),
            'monthly_limit' => 10000,
            'current_month_usage' => 0,
            'is_active' => true,
            'preferred_provider' => 'openai',
        ]);

        config([
            'normalizer.openai.api_key' => 'test-key',
            'normalizer.openai.model' => 'gpt-4o-mini',
            'normalizer.openai.timeout' => 10,
            'normalizer.openai.max_retries' => 0,
            'normalizer.anthropic.api_key' => 'test-key',
            'normalizer.anthropic.model' => 'claude-sonnet-4-20250514',
            'normalizer.anthropic.timeout' => 10,
            'normalizer.anthropic.max_retries' => 0,
            'normalizer.cache.enabled' => false,
        ]);
    }

    public function test_returns_401_without_token(): void
    {
        $response = $this->postJson('/api/v1/normalize/batch', [
            'addresses' => [
                ['country' => 'PL', 'city' => 'Warszawa', 'address' => 'Marszałkowska 1'],
            ],
        ]);

        $response->assertStatus(401);
    }

    public function test_rejects_more_than_50_addresses(): void
    {
        $addresses = array_fill(0, 51, [
            'country' => 'PL',
            'city' => 'Warszawa',
            'address' => 'Marszałkowska 1',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plainToken}",
        ])->postJson('/api/v1/normalize/batch', [
            'addresses' => $addresses,
        ]);

        $response->assertStatus(422);
    }

    public function test_returns_422_for_missing_required_fields_in_batch(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plainToken}",
        ])->postJson('/api/v1/normalize/batch', [
            'addresses' => [
                ['city' => 'Warszawa'], // missing country and address
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_batch_normalize_success(): void
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
                                    'country_code' => 'CZ',
                                    'region' => null,
                                    'postal_code' => '110 00',
                                    'city' => 'Praha',
                                    'street' => 'Vodičkova',
                                    'house_number' => '681/14',
                                    'apartment_number' => null,
                                    'company_name' => null,
                                    'removed_noise' => [],
                                    'confidence' => 0.92,
                                    'formatted' => 'Vodičkova 681/14, 110 00 Praha',
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plainToken}",
        ])->postJson('/api/v1/normalize/batch', [
            'addresses' => [
                [
                    'id' => 'order_123',
                    'country' => 'PL',
                    'postal_code' => '00-001',
                    'city' => 'Warszawa',
                    'address' => 'Marszałkowska 1',
                ],
                [
                    'id' => 'order_456',
                    'country' => 'CZ',
                    'postal_code' => '110 00',
                    'city' => 'Praha',
                    'address' => 'Vodičkova 681/14',
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'stats' => [
                    'total' => 2,
                    'failed' => 0,
                ],
            ])
            ->assertJsonCount(2, 'results');
    }

    public function test_batch_increments_usage_by_address_count(): void
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
                                    'country_code' => 'PL',
                                    'region' => null,
                                    'postal_code' => '30-001',
                                    'city' => 'Kraków',
                                    'street' => 'Długa',
                                    'house_number' => '5',
                                    'apartment_number' => null,
                                    'company_name' => null,
                                    'removed_noise' => [],
                                    'confidence' => 0.93,
                                    'formatted' => 'Długa 5, 30-001 Kraków',
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$this->plainToken}",
        ])->postJson('/api/v1/normalize/batch', [
            'addresses' => [
                ['country' => 'PL', 'postal_code' => '00-001', 'city' => 'Warszawa', 'address' => 'Marszałkowska 1'],
                ['country' => 'PL', 'postal_code' => '30-001', 'city' => 'Kraków', 'address' => 'Długa 5'],
            ],
        ]);

        $this->client->refresh();
        $this->assertEquals(2, $this->client->current_month_usage);
    }

    public function test_batch_rejects_when_limit_would_be_exceeded(): void
    {
        $this->client->update(['current_month_usage' => 9999, 'monthly_limit' => 10000]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plainToken}",
        ])->postJson('/api/v1/normalize/batch', [
            'addresses' => [
                ['country' => 'PL', 'city' => 'Warszawa', 'address' => 'Marszałkowska 1'],
                ['country' => 'PL', 'city' => 'Kraków', 'address' => 'Długa 5'],
            ],
        ]);

        $response->assertStatus(429);
    }
}
