<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NormalizeEndpointTest extends TestCase
{
    use RefreshDatabase;

    private ApiClient $client;

    private string $plainToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plainToken = 'test-token-123';
        $this->client = ApiClient::create([
            'name' => 'test-client',
            'api_key' => hash('sha256', $this->plainToken),
            'monthly_limit' => 1000,
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
        $response = $this->postJson('/api/v1/normalize', [
            'country' => 'PL',
            'city' => 'Warszawa',
            'address' => 'Marszałkowska 1',
        ]);

        $response->assertStatus(401);
    }

    public function test_returns_401_with_invalid_token(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid-token',
        ])->postJson('/api/v1/normalize', [
            'country' => 'PL',
            'city' => 'Warszawa',
            'address' => 'Marszałkowska 1',
        ]);

        $response->assertStatus(401);
    }

    public function test_returns_422_without_required_fields(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plainToken}",
        ])->postJson('/api/v1/normalize', [
            'city' => 'Warszawa',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['country', 'address']);
    }

    public function test_returns_200_with_valid_request(): void
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

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plainToken}",
        ])->postJson('/api/v1/normalize', [
            'country' => 'PL',
            'postal_code' => '00-001',
            'city' => 'Warszawa',
            'address' => 'Marszałkowska 1/2',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'data' => [
                    'country_code' => 'PL',
                    'city' => 'Warszawa',
                    'street' => 'Marszałkowska',
                ],
            ]);
    }

    public function test_returns_429_when_limit_exceeded(): void
    {
        $this->client->update(['current_month_usage' => 1000]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plainToken}",
        ])->postJson('/api/v1/normalize', [
            'country' => 'PL',
            'city' => 'Warszawa',
            'address' => 'Marszałkowska 1',
        ]);

        $response->assertStatus(429)
            ->assertJson(['status' => 'error']);
    }

    public function test_returns_401_for_deactivated_client(): void
    {
        $this->client->update(['is_active' => false]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plainToken}",
        ])->postJson('/api/v1/normalize', [
            'country' => 'PL',
            'city' => 'Warszawa',
            'address' => 'Marszałkowska 1',
        ]);

        $response->assertStatus(401);
    }

    public function test_increments_usage_on_success(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
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
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$this->plainToken}",
        ])->postJson('/api/v1/normalize', [
            'country' => 'PL',
            'city' => 'Warszawa',
            'address' => 'Marszałkowska 1',
            'postal_code' => '00-001',
        ]);

        $this->client->refresh();
        $this->assertEquals(1, $this->client->current_month_usage);
    }

    public function test_status_endpoint_returns_stats(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->plainToken}",
        ])->getJson('/api/v1/status');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'client' => 'test-client',
                'plan_limit' => 1000,
                'used_this_month' => 0,
                'remaining' => 1000,
            ]);
    }
}
