<?php

namespace App\Services;

class CsvFormatDetector
{
    public const VARIANT_MINIMAL = 'minimal';
    public const VARIANT_STANDARD = 'standard';
    public const VARIANT_FULL = 'full';

    private const HEADERS = [
        self::VARIANT_MINIMAL => ['reference', 'country', 'city', 'address'],
        self::VARIANT_STANDARD => ['reference', 'country', 'postal_code', 'city', 'address', 'full_name'],
        self::VARIANT_FULL => ['reference', 'country', 'postal_code', 'city', 'address', 'full_name', 'company_name'],
    ];

    /**
     * Detect format variant from the header row.
     *
     * @return string|null Variant name or null if unrecognized
     */
    public function detect(string $headerLine): ?string
    {
        $columns = array_map(
            fn (string $col) => strtolower(trim($col)),
            str_getcsv($headerLine, ';')
        );

        // Check from most specific to least specific
        foreach ([self::VARIANT_FULL, self::VARIANT_STANDARD, self::VARIANT_MINIMAL] as $variant) {
            if ($columns === self::HEADERS[$variant]) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * Get expected headers for a variant.
     */
    public static function headersFor(string $variant): array
    {
        return self::HEADERS[$variant] ?? [];
    }

    /**
     * Parse a CSV data row into a keyed array based on the variant.
     */
    public function parseRow(string $line, string $variant): ?array
    {
        $headers = self::HEADERS[$variant] ?? null;

        if (! $headers) {
            return null;
        }

        $values = str_getcsv($line, ';');

        if (count($values) !== count($headers)) {
            return null;
        }

        return array_combine($headers, array_map('trim', $values));
    }

    /**
     * Count data rows in a CSV file (excluding header).
     */
    public function countRows(string $filePath): int
    {
        $handle = fopen($filePath, 'r');

        if (! $handle) {
            return 0;
        }

        $count = -1; // Exclude header
        while (fgets($handle) !== false) {
            $count++;
        }

        fclose($handle);

        return max(0, $count);
    }

    /**
     * Get all supported variants with their headers for display.
     */
    public static function supportedVariants(): array
    {
        return self::HEADERS;
    }
}
