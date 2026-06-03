<?php

namespace App\Support\Tax;

class TaxIdFormatter
{
    /**
     * Format Tax ID based on country.
     * Supported countries: Indonesia (ID), Malaysia (MY), Singapore (SG)
     */
    public static function format(?string $taxId, ?string $countryCode = 'ID'): string
    {
        if (!$taxId) {
            return '';
        }

        // Clean up non-alphanumeric characters
        $cleanTaxId = preg_replace('/[^a-zA-Z0-9]/', '', $taxId);

        return match (strtoupper($countryCode)) {
            'ID', 'INDONESIA' => self::formatNPWP($cleanTaxId),
            'MY', 'MALAYSIA' => self::formatMalaysiaTax($cleanTaxId),
            'SG', 'SINGAPORE' => self::formatSingaporeUEN($cleanTaxId),
            default => $taxId,
        };
    }

    /**
     * Format Indonesia NPWP (00.000.000.0-000.000)
     */
    private static function formatNPWP(string $value): string
    {
        $length = strlen($value);

        if ($length < 15) {
            return $value; // Not enough digits to format
        }

        // If it's more than 15 digits (like 16 digits NIK/NPWP), we still try to format 
        // the first 15 or handle as 16.
        if ($length >= 15 && $length < 16) {
            // Format: 00.000.000.0-000.000
            return sprintf(
                "%s.%s.%s.%s-%s.%s",
                substr($value, 0, 2),
                substr($value, 2, 3),
                substr($value, 5, 3),
                substr($value, 8, 1),
                substr($value, 9, 3),
                substr($value, 12, 3)
            );
        }

        // 16 digits or more
        return sprintf(
            "%s-%s-%s-%s",
            substr($value, 0, 4),
            substr($value, 4, 4),
            substr($value, 8, 4),
            substr($value, 12, 4)
        );
    }

    /**
     * Format Malaysia Tax ID (e.g., C 1234567890 atau OG 123456789)
     */
    private static function formatMalaysiaTax(string $value): string
    {
        // Malaysia Tax ID varies by type, usually alpha prefix + numbers
        // Common format: XX 000000000
        if (preg_match('/^([a-zA-Z]{1,2})(\d+)$/', $value, $matches)) {
            return strtoupper($matches[1]) . ' ' . $matches[2];
        }
        return $value;
    }

    /**
     * Format Singapore UEN (Unique Entity Number)
     * Format: 000000000X or X00XX0000X
     */
    private static function formatSingaporeUEN(string $value): string
    {
        return strtoupper($value); // UEN is usually displayed as is, but in uppercase
    }
}
