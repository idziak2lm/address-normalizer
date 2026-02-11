<?php

namespace App\Services;

use App\Enums\CountryCode;

class StreetParser
{
    /**
     * Country-specific regex patterns for parsing street addresses.
     *
     * Groups: street, number, flat (optional)
     * Some countries put number before street (GB, IE, FR, MC, MT),
     * others put number after street (PL, DE, CZ, etc.).
     */
    private const PATTERNS = [
        // Number before street
        'GB' => '/^(?P<number>\d+[A-Za-z]?)\s*,?\s*(?P<street>.+)$/u',
        'IE' => '/^(?P<number>\d+[A-Za-z]?)\s*,?\s*(?P<street>.+)$/u',
        'FR' => '/^(?P<number>\d+[A-Za-z]?)\s*,?\s*(?P<street>.+)$/u',
        'MC' => '/^(?P<number>\d+[A-Za-z]?)\s*,?\s*(?P<street>.+)$/u',
        'MT' => '/^(?P<number>\d+[A-Za-z]?)\s*,?\s*(?P<street>.+)$/u',

        // Number after street
        'PL' => '/^(?P<street>.+?)\s+(?P<number>\d+[A-Za-z]?(?:\s?[\/]\s?\d+[A-Za-z]?)?)$/u',
        'DE' => '/^(?P<street>.+?)\s+(?P<number>\d+\s?[a-zA-Z]?)$/u',
        'AT' => '/^(?P<street>.+?)\s+(?P<number>\d+\s?[a-zA-Z]?)$/u',
        'CH' => '/^(?P<street>.+?)\s+(?P<number>\d+\s?[a-zA-Z]?)$/u',
        'CZ' => '/^(?P<street>.+?)\s+(?P<number>\d+(?:\/\d+)?)$/u',
        'SK' => '/^(?P<street>.+?)\s+(?P<number>\d+(?:\/\d+)?)$/u',
        'HU' => '/^(?P<street>.+?)\s+(?P<number>\d+[\.]?)\s*(?:em\.|ph\.)?.*$/u',
        'RO' => '/^(?P<street>.+?)\s+(?P<number>\d+[A-Za-z]?)(?:,\s*(?:ap|et)\.?\s*(?P<flat>\d+))?$/u',
        'BG' => '/^(?P<street>.+?)\s+(?P<number>\d+[A-Za-z]?)(?:.*)?$/u',
        'UA' => '/^(?P<street>.+?)\s+(?P<number>\d+[A-Za-z]?)(?:\/(?P<flat>\d+))?$/u',
        'IT' => '/^(?P<street>.+?)[,\s]+(?P<number>\d+\/?\s?[A-Za-z]?)$/u',
        'ES' => '/^(?P<street>.+?)[,\s]+(?P<number>\d+\s?(?:bis|dup|trip|[A-Za-z])?)$/u',
        'PT' => '/^(?P<street>.+?)[,\s]+(?P<number>\d+[A-Za-z\x{00BA}\x{00AA}]?)(?:.*)?$/u',
        'NL' => '/^(?P<street>.+?)\s+(?P<number>\d+)\s?(?P<flat>[A-Za-z\d\s\-]*)$/u',
        'BE' => '/^(?P<street>.+?)\s+(?P<number>\d+[A-Za-z]?)$/u',
        'LU' => '/^(?P<street>.+?)\s+(?P<number>\d+[A-Za-z]?)$/u',
        'GR' => '/^(?P<street>.+?)\s+(?P<number>\d+[A-Za-z]?)$/u',
        'SE' => '/^(?P<street>.+?)\s+(?P<number>\d+[A-Za-z]?)$/u',
        'NO' => '/^(?P<street>.+?)\s+(?P<number>\d+[A-Za-z]?)$/u',
        'DK' => '/^(?P<street>.+?)\s+(?P<number>\d+[A-Za-z]?)(?:.*)?$/u',
        'FI' => '/^(?P<street>.+?)\s+(?P<number>\d+[A-Za-z]?)(?:\s+(?P<flat>[A-Za-z\d]+))?$/u',
        'EE' => '/^(?P<street>.+?)\s+(?P<number>\d+[A-Za-z]?)(?:\-(?P<flat>\d+))?$/u',
        'LV' => '/^(?P<street>.+?)\s+(?P<number>\d+[A-Za-z]?)(?:\-(?P<flat>\d+))?$/u',
        'LT' => '/^(?P<street>.+?)\s+(?P<number>\d+[A-Za-z]?)(?:\-(?P<flat>\d+))?$/u',
    ];

    /**
     * Parse a street address using country-specific regex.
     *
     * @return array{street: string, house_number: string, apartment_number: string|null}|null
     */
    public function parse(string $countryCode, string $address): ?array
    {
        $address = $this->cleanAddress($address);

        if ($address === '') {
            return null;
        }

        $countryCode = strtoupper($countryCode);
        $pattern = self::PATTERNS[$countryCode] ?? null;

        if (! $pattern) {
            return null;
        }

        if (! preg_match($pattern, $address, $matches)) {
            return null;
        }

        $street = trim($matches['street'] ?? '');
        $number = trim($matches['number'] ?? '');
        $flat = isset($matches['flat']) ? trim($matches['flat']) : null;

        if ($street === '' || $number === '') {
            return null;
        }

        // Normalize house number: remove internal spaces (e.g. "16 A" → "16A")
        $number = preg_replace('/(\d+)\s+([A-Za-z])$/', '$1$2', $number);

        // Clean empty flat
        if ($flat === '') {
            $flat = null;
        }

        return [
            'street' => $street,
            'house_number' => $number,
            'apartment_number' => $flat,
        ];
    }

    /**
     * Format regex parse result as a hint string for AI.
     */
    public function formatHint(string $countryCode, string $address): ?string
    {
        $parsed = $this->parse($countryCode, $address);

        if (! $parsed) {
            return null;
        }

        $hint = "Regex pre-parse suggests: street=\"{$parsed['street']}\", house_number=\"{$parsed['house_number']}\"";

        if ($parsed['apartment_number']) {
            $hint .= ", apartment_number=\"{$parsed['apartment_number']}\"";
        }

        return $hint;
    }

    /**
     * Check if a country has a regex pattern available.
     */
    public function hasPattern(string $countryCode): bool
    {
        return isset(self::PATTERNS[strtoupper($countryCode)]);
    }

    /**
     * Strip only the "ul."/"ulica" prefix before applying regex.
     *
     * We KEEP "al."/"aleja", "pl."/"plac", "os."/"osiedle" because they
     * distinguish address types (avenue vs street, square vs street, estate vs street).
     * Example: "pl. Kościuszki" and "ul. Kościuszki" in Wrocław are different locations.
     */
    private function cleanAddress(string $address): string
    {
        $address = trim($address);

        // Only remove "ul." / "ul " / "ulica " — the default type that carries no meaning
        $address = preg_replace('/^(ul\.|ul\s|ulica\s)\s*/iu', '', $address);

        return trim($address);
    }
}
