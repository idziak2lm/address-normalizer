<?php

namespace Tests\Unit;

use App\Services\CsvFormatDetector;
use Tests\TestCase;

class CsvFormatDetectorTest extends TestCase
{
    private CsvFormatDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new CsvFormatDetector;
    }

    public function test_detects_minimal_format(): void
    {
        $result = $this->detector->detect('reference;country;city;address');

        $this->assertEquals(CsvFormatDetector::VARIANT_MINIMAL, $result);
    }

    public function test_detects_standard_format(): void
    {
        $result = $this->detector->detect('reference;country;postal_code;city;address;full_name');

        $this->assertEquals(CsvFormatDetector::VARIANT_STANDARD, $result);
    }

    public function test_detects_full_format(): void
    {
        $result = $this->detector->detect('reference;country;postal_code;city;address;full_name;company_name');

        $this->assertEquals(CsvFormatDetector::VARIANT_FULL, $result);
    }

    public function test_detects_with_extra_whitespace(): void
    {
        $result = $this->detector->detect(' reference ; country ; city ; address ');

        $this->assertEquals(CsvFormatDetector::VARIANT_MINIMAL, $result);
    }

    public function test_detects_case_insensitive(): void
    {
        $result = $this->detector->detect('Reference;Country;City;Address');

        $this->assertEquals(CsvFormatDetector::VARIANT_MINIMAL, $result);
    }

    public function test_returns_null_for_unknown_format(): void
    {
        $result = $this->detector->detect('id;name;email');

        $this->assertNull($result);
    }

    public function test_returns_null_for_wrong_column_order(): void
    {
        $result = $this->detector->detect('country;reference;city;address');

        $this->assertNull($result);
    }

    public function test_returns_null_for_comma_separator(): void
    {
        $result = $this->detector->detect('reference,country,city,address');

        $this->assertNull($result);
    }

    public function test_parse_row_minimal(): void
    {
        $row = $this->detector->parseRow('ORD-123;PL;Warszawa;Marszalkowska 1', CsvFormatDetector::VARIANT_MINIMAL);

        $this->assertNotNull($row);
        $this->assertEquals('ORD-123', $row['reference']);
        $this->assertEquals('PL', $row['country']);
        $this->assertEquals('Warszawa', $row['city']);
        $this->assertEquals('Marszalkowska 1', $row['address']);
    }

    public function test_parse_row_standard(): void
    {
        $row = $this->detector->parseRow(
            'ORD-123;PL;00-001;Warszawa;Marszalkowska 1;Jan Kowalski',
            CsvFormatDetector::VARIANT_STANDARD
        );

        $this->assertNotNull($row);
        $this->assertEquals('00-001', $row['postal_code']);
        $this->assertEquals('Jan Kowalski', $row['full_name']);
    }

    public function test_parse_row_full(): void
    {
        $row = $this->detector->parseRow(
            'ORD-123;PL;00-001;Warszawa;Marszalkowska 1;Jan Kowalski;FHU Kowalski',
            CsvFormatDetector::VARIANT_FULL
        );

        $this->assertNotNull($row);
        $this->assertEquals('FHU Kowalski', $row['company_name']);
    }

    public function test_parse_row_returns_null_for_wrong_column_count(): void
    {
        $row = $this->detector->parseRow('ORD-123;PL;Warszawa', CsvFormatDetector::VARIANT_MINIMAL);

        $this->assertNull($row);
    }

    public function test_parse_row_trims_values(): void
    {
        $row = $this->detector->parseRow(' ORD-123 ; PL ; Warszawa ; Marszalkowska 1 ', CsvFormatDetector::VARIANT_MINIMAL);

        $this->assertNotNull($row);
        $this->assertEquals('ORD-123', $row['reference']);
        $this->assertEquals('PL', $row['country']);
    }

    public function test_count_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "reference;country;city;address\nORD-1;PL;Warszawa;Test 1\nORD-2;DE;Berlin;Test 2\n");

        $count = $this->detector->countRows($path);

        $this->assertEquals(2, $count);

        unlink($path);
    }

    public function test_count_rows_empty_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "reference;country;city;address\n");

        $count = $this->detector->countRows($path);

        $this->assertEquals(0, $count);

        unlink($path);
    }

    public function test_supported_variants_returns_all(): void
    {
        $variants = CsvFormatDetector::supportedVariants();

        $this->assertArrayHasKey('minimal', $variants);
        $this->assertArrayHasKey('standard', $variants);
        $this->assertArrayHasKey('full', $variants);
    }
}
