<?php

namespace Tests\Unit;

use App\DTOs\RawAddressInput;
use App\Services\PreCleaner;
use PHPUnit\Framework\TestCase;

class PreCleanerTest extends TestCase
{
    private PreCleaner $cleaner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleaner = new PreCleaner;
    }

    public function test_removes_phone_numbers(): void
    {
        $input = new RawAddressInput(
            country: 'PL',
            city: 'Warszawa',
            address: 'Marszałkowska 1/2 tel. 500100200',
        );

        $result = $this->cleaner->clean($input);

        $this->assertStringNotContainsString('500100200', $result->address);
        $this->assertStringContainsString('Marszałkowska 1/2', $result->address);
    }

    public function test_removes_phone_with_country_code(): void
    {
        $input = new RawAddressInput(
            country: 'PL',
            city: 'Kraków',
            address: 'Długa 5 +48 500 100 200',
        );

        $result = $this->cleaner->clean($input);

        $this->assertStringNotContainsString('500 100 200', $result->address);
        $this->assertStringNotContainsString('+48', $result->address);
    }

    public function test_removes_email_addresses(): void
    {
        $input = new RawAddressInput(
            country: 'PL',
            city: 'Warszawa',
            address: 'ul. Piękna 5 jan@example.com',
        );

        $result = $this->cleaner->clean($input);

        $this->assertStringNotContainsString('jan@example.com', $result->address);
        $this->assertStringContainsString('ul. Piękna 5', $result->address);
    }

    public function test_normalizes_whitespace(): void
    {
        $input = new RawAddressInput(
            country: 'PL',
            city: "Warszawa  \t  ",
            address: "ul.  Marszałkowska   1",
        );

        $result = $this->cleaner->clean($input);

        $this->assertEquals('Warszawa', $result->city);
        $this->assertEquals('ul. Marszałkowska 1', $result->address);
    }

    public function test_removes_emojis(): void
    {
        $input = new RawAddressInput(
            country: 'PL',
            city: 'Warszawa 🏠',
            address: 'Marszałkowska 1 📦',
        );

        $result = $this->cleaner->clean($input);

        $this->assertEquals('Warszawa', $result->city);
        $this->assertStringNotContainsString('📦', $result->address);
    }

    public function test_does_not_modify_clean_address(): void
    {
        $input = new RawAddressInput(
            country: 'PL',
            city: 'Warszawa',
            address: 'Normal address 123',
        );

        $result = $this->cleaner->clean($input);

        $this->assertEquals('Warszawa', $result->city);
        $this->assertEquals('Normal address 123', $result->address);
    }

    public function test_preserves_id_field(): void
    {
        $input = new RawAddressInput(
            country: 'PL',
            city: 'Warszawa',
            address: 'Marszałkowska 1',
            id: 'order_123',
        );

        $result = $this->cleaner->clean($input);

        $this->assertEquals('order_123', $result->id);
    }

    public function test_handles_newlines_and_tabs(): void
    {
        $input = new RawAddressInput(
            country: 'PL',
            city: "Kraków\r\n",
            address: "ul. Długa\t5",
        );

        $result = $this->cleaner->clean($input);

        $this->assertEquals('Kraków', $result->city);
        $this->assertEquals('ul. Długa 5', $result->address);
    }
}
