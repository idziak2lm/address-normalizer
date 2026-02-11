<?php

namespace App\Services;

use App\DTOs\RawAddressInput;

class PreCleaner
{
    /**
     * Regex pre-cleaning BEFORE sending to AI.
     * Goal: reduce token count and help AI focus.
     */
    public function clean(RawAddressInput $input): RawAddressInput
    {
        return new RawAddressInput(
            country: $this->cleanField($input->country),
            city: $this->cleanField($input->city),
            address: $this->cleanField($input->address),
            postal_code: $input->postal_code ? $this->cleanField($input->postal_code) : null,
            full_name: $input->full_name ? $this->cleanField($input->full_name) : null,
            id: $input->id,
        );
    }

    private function cleanField(string $value): string
    {
        // Remove emojis (unicode emoji ranges)
        $value = preg_replace('/[\x{1F600}-\x{1F64F}]/u', '', $value);  // Emoticons
        $value = preg_replace('/[\x{1F300}-\x{1F5FF}]/u', '', $value);  // Misc Symbols
        $value = preg_replace('/[\x{1F680}-\x{1F6FF}]/u', '', $value);  // Transport
        $value = preg_replace('/[\x{1F1E0}-\x{1F1FF}]/u', '', $value);  // Flags
        $value = preg_replace('/[\x{2600}-\x{26FF}]/u', '', $value);    // Misc symbols
        $value = preg_replace('/[\x{2700}-\x{27BF}]/u', '', $value);    // Dingbats
        $value = preg_replace('/[\x{FE00}-\x{FE0F}]/u', '', $value);    // Variation selectors
        $value = preg_replace('/[\x{1F900}-\x{1F9FF}]/u', '', $value);  // Supplemental
        $value = preg_replace('/[\x{200D}]/u', '', $value);             // Zero-width joiner

        // Remove phone numbers: +48 500 100 200, 500-100-200, etc.
        $value = preg_replace('/(\+?\d{2,3}[\s.\-]?)?\d{3}[\s.\-]?\d{3}[\s.\-]?\d{3}/', '', $value);

        // Remove email addresses
        $value = preg_replace('/[\w.\-]+@[\w.\-]+\.\w+/', '', $value);

        // Replace tabs, carriage returns with spaces
        $value = str_replace(["\t", "\r\n", "\r", "\n"], ' ', $value);

        // Collapse multiple spaces to single space
        $value = preg_replace('/\s{2,}/', ' ', $value);

        return trim($value);
    }
}
