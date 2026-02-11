<?php

namespace Tests\Unit;

use App\DTOs\RawAddressInput;
use App\Exceptions\NormalizationException;
use App\Services\LlmProviders\AnthropicProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'normalizer.anthropic.api_key' => 'test-key',
            'normalizer.anthropic.model' => 'claude-sonnet-4-20250514',
            'normalizer.anthropic.timeout' => 10,
            'normalizer.anthropic.max_retries' => 0,
        ]);
    }

    public function test_normalize_single_address(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'country_code' => 'CZ',
                            'region' => 'Hlavní město Praha',
                            'postal_code' => '110 00',
                            'city' => 'Praha 1',
                            'street' => 'Vodičkova',
                            'house_number' => '681/14',
                            'apartment_number' => null,
                            'company_name' => null,
                            'removed_noise' => [],
                            'confidence' => 0.92,
                            'formatted' => 'Vodičkova 681/14, 110 00 Praha 1',
                        ]),
                    ],
                ],
            ]),
        ]);

        $provider = new AnthropicProvider;
        $input = new RawAddressInput(
            country: 'CZ',
            city: 'Praha 1',
            address: 'Vodičkova 681/14',
            postal_code: '110 00',
        );

        $result = $provider->normalize($input);

        $this->assertEquals('CZ', $result->country_code);
        $this->assertEquals('Praha 1', $result->city);
        $this->assertEquals('Vodičkova', $result->street);
        $this->assertEquals(0.92, $result->confidence);

        Http::assertSentCount(1);
    }

    public function test_handles_markdown_wrapped_json(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "```json\n" . json_encode([
                            'country_code' => 'PL',
                            'region' => null,
                            'postal_code' => '00-001',
                            'city' => 'Warszawa',
                            'street' => 'Marszałkowska',
                            'house_number' => '1',
                            'apartment_number' => null,
                            'company_name' => null,
                            'removed_noise' => [],
                            'confidence' => 0.90,
                            'formatted' => 'Marszałkowska 1, 00-001 Warszawa',
                        ]) . "\n```",
                    ],
                ],
            ]),
        ]);

        $provider = new AnthropicProvider;
        $input = new RawAddressInput(
            country: 'PL',
            city: 'Warszawa',
            address: 'Marszałkowska 1',
            postal_code: '00-001',
        );

        $result = $provider->normalize($input);

        $this->assertEquals('PL', $result->country_code);
        $this->assertEquals('Warszawa', $result->city);
    }

    public function test_throws_on_api_failure(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $provider = new AnthropicProvider;
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
        $provider = new AnthropicProvider;
        $this->assertEquals('anthropic', $provider->name());
    }
}
