<?php

namespace App\Support;

/**
 * Canonical Indonesian WhatsApp numbers for cache keys, DB storage, and Fonnte.
 */
final class WhatsappNumber
{
    /** Indonesian mobile: 62 + 8xx + 8–11 digits */
    private const VALID_PATTERN = '/^628\d{8,11}$/';

    public static function normalize(?string $phone): string
    {
        if ($phone === null || $phone === '') {
            return '';
        }

        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // Common typo: 685xxxxxxxxxx instead of 085xxxxxxxxxx
        if (preg_match('/^685\d{9,10}$/', $digits)) {
            $digits = '0'.substr($digits, 1);
        }

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (! str_starts_with($digits, '62') && str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return $digits;
    }

    public static function isValid(string $normalized): bool
    {
        return $normalized !== '' && (bool) preg_match(self::VALID_PATTERN, $normalized);
    }

    /**
     * Full international number for Fonnte (628xxxxxxxxxx).
     */
    public static function fonnteTarget(string $normalized): string
    {
        return $normalized;
    }
}
