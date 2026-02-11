<?php

namespace Tests\Unit;

use App\DTOs\NormalizedAddress;
use App\Services\PostValidator;
use Tests\TestCase;

class PostValidatorTest extends TestCase
{
    private PostValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new PostValidator;
    }

    public function test_valid_polish_postal_code(): void
    {
        $this->assertTrue($this->validator->isValidPostalCode('PL', '00-001'));
        $this->assertTrue($this->validator->isValidPostalCode('PL', '30-001'));
    }

    public function test_invalid_polish_postal_code(): void
    {
        $this->assertFalse($this->validator->isValidPostalCode('PL', '00001'));
        $this->assertFalse($this->validator->isValidPostalCode('PL', '123'));
    }

    public function test_valid_czech_postal_code(): void
    {
        $this->assertTrue($this->validator->isValidPostalCode('CZ', '110 00'));
        $this->assertTrue($this->validator->isValidPostalCode('CZ', '11000'));
    }

    public function test_valid_german_postal_code(): void
    {
        $this->assertTrue($this->validator->isValidPostalCode('DE', '10115'));
    }

    public function test_valid_uk_postal_code(): void
    {
        $this->assertTrue($this->validator->isValidPostalCode('GB', 'SW1A 1AA'));
        $this->assertTrue($this->validator->isValidPostalCode('GB', 'EC1A 1BB'));
    }

    public function test_valid_dutch_postal_code(): void
    {
        $this->assertTrue($this->validator->isValidPostalCode('NL', '1012 JS'));
        $this->assertTrue($this->validator->isValidPostalCode('NL', '1012JS'));
    }

    public function test_valid_portuguese_postal_code(): void
    {
        $this->assertTrue($this->validator->isValidPostalCode('PT', '1000-001'));
    }

    public function test_valid_romanian_postal_code(): void
    {
        $this->assertTrue($this->validator->isValidPostalCode('RO', '010101'));
    }

    public function test_valid_lithuanian_postal_code(): void
    {
        $this->assertTrue($this->validator->isValidPostalCode('LT', 'LT-01001'));
    }

    public function test_valid_latvian_postal_code(): void
    {
        $this->assertTrue($this->validator->isValidPostalCode('LV', 'LV-1001'));
    }

    public function test_invalid_country_code(): void
    {
        $this->assertFalse($this->validator->isValidPostalCode('XX', '12345'));
    }

    public function test_validate_lowers_confidence_for_bad_postal(): void
    {
        $address = new NormalizedAddress(
            country_code: 'PL',
            region: null,
            postal_code: '00001', // Invalid for PL
            city: 'Warszawa',
            street: 'Marszałkowska',
            house_number: '1',
            apartment_number: null,
            company_name: null,
            formatted: 'Marszałkowska 1, 00001 Warszawa',
            removed_noise: [],
            confidence: 0.95,
        );

        $result = $this->validator->validate($address);

        $this->assertLessThan(0.95, $result->confidence);
    }

    public function test_validate_lowers_confidence_for_invalid_country(): void
    {
        $address = new NormalizedAddress(
            country_code: 'XX',
            region: null,
            postal_code: '12345',
            city: 'Unknown',
            street: 'Street',
            house_number: '1',
            apartment_number: null,
            company_name: null,
            formatted: 'Street 1, 12345 Unknown',
            removed_noise: [],
            confidence: 0.90,
        );

        $result = $this->validator->validate($address);

        $this->assertLessThan(0.90, $result->confidence);
    }

    public function test_validate_keeps_confidence_for_valid_address(): void
    {
        $address = new NormalizedAddress(
            country_code: 'PL',
            region: 'mazowieckie',
            postal_code: '00-001',
            city: 'Warszawa',
            street: 'Marszałkowska',
            house_number: '1',
            apartment_number: '2',
            company_name: null,
            formatted: 'Marszałkowska 1/2, 00-001 Warszawa',
            removed_noise: [],
            confidence: 0.95,
        );

        $result = $this->validator->validate($address);

        $this->assertEquals(0.95, $result->confidence);
    }

    public function test_validate_detects_house_number_looking_like_postal_code(): void
    {
        $address = new NormalizedAddress(
            country_code: 'DE',
            region: null,
            postal_code: '10115',
            city: 'Berlin',
            street: 'Friedrichstraße',
            house_number: '10115', // This looks like a postal code
            apartment_number: null,
            company_name: null,
            formatted: 'Friedrichstraße 10115, 10115 Berlin',
            removed_noise: [],
            confidence: 0.90,
        );

        $result = $this->validator->validate($address);

        $this->assertLessThan(0.90, $result->confidence);
    }
}
