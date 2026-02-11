<?php

namespace Tests\Unit;

use App\Services\StreetParser;
use Tests\TestCase;

class StreetParserTest extends TestCase
{
    private StreetParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new StreetParser;
    }

    // === Polish addresses (number after street) ===

    public function test_polish_simple_address(): void
    {
        $result = $this->parser->parse('PL', 'Marszałkowska 15');
        $this->assertNotNull($result);
        $this->assertEquals('Marszałkowska', $result['street']);
        $this->assertEquals('15', $result['house_number']);
        $this->assertNull($result['apartment_number']);
    }

    public function test_polish_address_with_apartment(): void
    {
        $result = $this->parser->parse('PL', 'Marszałkowska 15/4');
        $this->assertNotNull($result);
        $this->assertEquals('Marszałkowska', $result['street']);
        $this->assertEquals('15/4', $result['house_number']);
        $this->assertNull($result['apartment_number']);
    }

    public function test_polish_address_with_letter_suffix(): void
    {
        $result = $this->parser->parse('PL', 'Wybrzeże Władysława 16A');
        $this->assertNotNull($result);
        $this->assertEquals('Wybrzeże Władysława', $result['street']);
        $this->assertEquals('16A', $result['house_number']);
        $this->assertNull($result['apartment_number']);
    }

    public function test_polish_address_strips_ul_prefix(): void
    {
        $result = $this->parser->parse('PL', 'ul. Długa 5');
        $this->assertNotNull($result);
        $this->assertEquals('Długa', $result['street']);
        $this->assertEquals('5', $result['house_number']);
    }

    public function test_polish_address_strips_ulica_prefix(): void
    {
        $result = $this->parser->parse('PL', 'ulica Piękna 12');
        $this->assertNotNull($result);
        $this->assertEquals('Piękna', $result['street']);
        $this->assertEquals('12', $result['house_number']);
    }

    public function test_polish_address_keeps_aleja_prefix(): void
    {
        $result = $this->parser->parse('PL', 'al. Jerozolimskie 100');
        $this->assertNotNull($result);
        $this->assertEquals('al. Jerozolimskie', $result['street']);
        $this->assertEquals('100', $result['house_number']);
    }

    public function test_polish_address_keeps_aleja_full_word(): void
    {
        $result = $this->parser->parse('PL', 'Aleja Jana Pawła II 10');
        $this->assertNotNull($result);
        $this->assertEquals('Aleja Jana Pawła II', $result['street']);
        $this->assertEquals('10', $result['house_number']);
    }

    public function test_polish_address_keeps_plac_prefix(): void
    {
        $result = $this->parser->parse('PL', 'pl. Kościuszki 3');
        $this->assertNotNull($result);
        $this->assertEquals('pl. Kościuszki', $result['street']);
        $this->assertEquals('3', $result['house_number']);
    }

    public function test_polish_address_keeps_plac_full_word(): void
    {
        $result = $this->parser->parse('PL', 'Plac Wolności 1');
        $this->assertNotNull($result);
        $this->assertEquals('Plac Wolności', $result['street']);
        $this->assertEquals('1', $result['house_number']);
    }

    public function test_polish_address_keeps_osiedle_abbreviated(): void
    {
        $result = $this->parser->parse('PL', 'os. Słoneczne 5');
        $this->assertNotNull($result);
        $this->assertEquals('os. Słoneczne', $result['street']);
        $this->assertEquals('5', $result['house_number']);
    }

    public function test_polish_osiedle_address(): void
    {
        $result = $this->parser->parse('PL', 'Osiedle Słoneczne 5');
        $this->assertNotNull($result);
        $this->assertEquals('Osiedle Słoneczne', $result['street']);
        $this->assertEquals('5', $result['house_number']);
    }

    public function test_polish_address_with_letter_and_apartment(): void
    {
        $result = $this->parser->parse('PL', 'Marszałkowska 16A/3');
        $this->assertNotNull($result);
        $this->assertEquals('Marszałkowska', $result['street']);
        $this->assertEquals('16A/3', $result['house_number']);
    }

    // === German addresses (number after street) ===

    public function test_german_simple_address(): void
    {
        $result = $this->parser->parse('DE', 'Friedrichstraße 123');
        $this->assertNotNull($result);
        $this->assertEquals('Friedrichstraße', $result['street']);
        $this->assertEquals('123', $result['house_number']);
    }

    public function test_german_address_with_letter(): void
    {
        $result = $this->parser->parse('DE', 'Berliner Straße 45a');
        $this->assertNotNull($result);
        $this->assertEquals('Berliner Straße', $result['street']);
        $this->assertEquals('45a', $result['house_number']);
    }

    // === Czech addresses (number after street) ===

    public function test_czech_simple_address(): void
    {
        $result = $this->parser->parse('CZ', 'Vodičkova 681/14');
        $this->assertNotNull($result);
        $this->assertEquals('Vodičkova', $result['street']);
        $this->assertEquals('681/14', $result['house_number']);
    }

    // === British addresses (number before street) ===

    public function test_british_number_before_street(): void
    {
        $result = $this->parser->parse('GB', '10 Downing Street');
        $this->assertNotNull($result);
        $this->assertEquals('Downing Street', $result['street']);
        $this->assertEquals('10', $result['house_number']);
    }

    public function test_british_number_with_letter(): void
    {
        $result = $this->parser->parse('GB', '12A Baker Street');
        $this->assertNotNull($result);
        $this->assertEquals('Baker Street', $result['street']);
        $this->assertEquals('12A', $result['house_number']);
    }

    public function test_british_with_comma(): void
    {
        $result = $this->parser->parse('GB', '25, High Street');
        $this->assertNotNull($result);
        $this->assertEquals('High Street', $result['street']);
        $this->assertEquals('25', $result['house_number']);
    }

    // === French addresses (number before street) ===

    public function test_french_address(): void
    {
        $result = $this->parser->parse('FR', '15 Rue de Rivoli');
        $this->assertNotNull($result);
        $this->assertEquals('Rue de Rivoli', $result['street']);
        $this->assertEquals('15', $result['house_number']);
    }

    // === Dutch addresses (number after street, optional flat) ===

    public function test_dutch_address(): void
    {
        $result = $this->parser->parse('NL', 'Damrak 1');
        $this->assertNotNull($result);
        $this->assertEquals('Damrak', $result['street']);
        $this->assertEquals('1', $result['house_number']);
    }

    public function test_dutch_address_with_flat(): void
    {
        $result = $this->parser->parse('NL', 'Keizersgracht 100 2A');
        $this->assertNotNull($result);
        $this->assertEquals('Keizersgracht', $result['street']);
        $this->assertEquals('100', $result['house_number']);
        $this->assertEquals('2A', $result['apartment_number']);
    }

    // === Romanian addresses (optional flat) ===

    public function test_romanian_address_with_apartment(): void
    {
        $result = $this->parser->parse('RO', 'Strada Victoriei 10, ap. 5');
        $this->assertNotNull($result);
        $this->assertEquals('Strada Victoriei', $result['street']);
        $this->assertEquals('10', $result['house_number']);
        $this->assertEquals('5', $result['apartment_number']);
    }

    // === Ukrainian addresses (optional flat via slash) ===

    public function test_ukrainian_address_with_flat(): void
    {
        $result = $this->parser->parse('UA', 'Хрещатик 22/5');
        $this->assertNotNull($result);
        $this->assertEquals('Хрещатик', $result['street']);
        $this->assertEquals('22', $result['house_number']);
        $this->assertEquals('5', $result['apartment_number']);
    }

    // === Baltic addresses (flat via dash) ===

    public function test_estonian_address_with_flat(): void
    {
        $result = $this->parser->parse('EE', 'Narva maantee 7-3');
        $this->assertNotNull($result);
        $this->assertEquals('Narva maantee', $result['street']);
        $this->assertEquals('7', $result['house_number']);
        $this->assertEquals('3', $result['apartment_number']);
    }

    public function test_latvian_address_with_flat(): void
    {
        $result = $this->parser->parse('LV', 'Brīvības iela 100-12');
        $this->assertNotNull($result);
        $this->assertEquals('Brīvības iela', $result['street']);
        $this->assertEquals('100', $result['house_number']);
        $this->assertEquals('12', $result['apartment_number']);
    }

    // === Finnish addresses (optional flat as separate token) ===

    public function test_finnish_address_with_flat(): void
    {
        $result = $this->parser->parse('FI', 'Mannerheimintie 10 A');
        $this->assertNotNull($result);
        $this->assertEquals('Mannerheimintie', $result['street']);
        $this->assertEquals('10', $result['house_number']);
        $this->assertEquals('A', $result['apartment_number']);
    }

    // === Edge cases ===

    public function test_empty_address_returns_null(): void
    {
        $this->assertNull($this->parser->parse('PL', ''));
        $this->assertNull($this->parser->parse('PL', '   '));
    }

    public function test_unknown_country_returns_null(): void
    {
        $this->assertNull($this->parser->parse('XX', 'Some Street 5'));
    }

    public function test_address_without_number_returns_null(): void
    {
        $this->assertNull($this->parser->parse('PL', 'Marszałkowska'));
    }

    public function test_has_pattern(): void
    {
        $this->assertTrue($this->parser->hasPattern('PL'));
        $this->assertTrue($this->parser->hasPattern('DE'));
        $this->assertTrue($this->parser->hasPattern('GB'));
        $this->assertFalse($this->parser->hasPattern('XX'));
    }

    public function test_format_hint(): void
    {
        $hint = $this->parser->formatHint('PL', 'Marszałkowska 15');
        $this->assertNotNull($hint);
        $this->assertStringContainsString('street="Marszałkowska"', $hint);
        $this->assertStringContainsString('house_number="15"', $hint);
    }

    public function test_format_hint_returns_null_for_unparseable(): void
    {
        $this->assertNull($this->parser->formatHint('XX', 'Something'));
    }

    public function test_case_insensitive_country_code(): void
    {
        $result = $this->parser->parse('pl', 'Marszałkowska 15');
        $this->assertNotNull($result);
        $this->assertEquals('Marszałkowska', $result['street']);
    }

    public function test_normalizes_space_in_house_number(): void
    {
        $result = $this->parser->parse('DE', 'Berliner Straße 45 a');
        $this->assertNotNull($result);
        $this->assertEquals('45a', $result['house_number']);
    }
}
