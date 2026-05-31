<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OtpStore
{
    private const OTP_TTL_MINUTES = 10;

    private const VERIFIED_TTL_MINUTES = 15;

    /**
     * @return array{otp: string, token: string, whatsapp: string}
     */
    public static function issue(string $whatsapp): array
    {
        $existing = DB::table('whatsapp_otp_codes')
            ->where('whatsapp', $whatsapp)
            ->where('expires_at', '>', now())
            ->first();

        if ($existing !== null && $existing->code !== '') {
            $otp = (string) $existing->code;
            $token = $existing->otp_token ?: self::generateToken();

            DB::table('whatsapp_otp_codes')->updateOrInsert(
                ['whatsapp' => $whatsapp],
                [
                    'code' => $otp,
                    'otp_token' => $token,
                    'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
                    'created_at' => now(),
                ]
            );

            return ['otp' => $otp, 'token' => $token, 'whatsapp' => $whatsapp];
        }

        $otp = (string) random_int(100000, 999999);
        $token = self::generateToken();

        DB::table('whatsapp_otp_codes')->updateOrInsert(
            ['whatsapp' => $whatsapp],
            [
                'code' => $otp,
                'otp_token' => $token,
                'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
                'created_at' => now(),
            ]
        );

        return ['otp' => $otp, 'token' => $token, 'whatsapp' => $whatsapp];
    }

    public static function hasPending(string $whatsapp): bool
    {
        return DB::table('whatsapp_otp_codes')
            ->where('whatsapp', $whatsapp)
            ->where('expires_at', '>', now())
            ->exists();
    }

    public static function hasPendingToken(string $token): bool
    {
        return DB::table('whatsapp_otp_codes')
            ->where('otp_token', $token)
            ->where('expires_at', '>', now())
            ->exists();
    }

    public static function verify(string $whatsapp, string $otp): bool
    {
        if (strlen($otp) !== 6) {
            return false;
        }

        $row = DB::table('whatsapp_otp_codes')
            ->where('whatsapp', $whatsapp)
            ->where('expires_at', '>', now())
            ->first();

        if (! $row) {
            return false;
        }

        return hash_equals((string) $row->code, $otp);
    }

    /**
     * Verify by token — avoids whatsapp format mismatch between send & verify.
     */
    public static function verifyByToken(string $token, string $otp): ?string
    {
        if ($token === '' || strlen($otp) !== 6) {
            return null;
        }

        $row = DB::table('whatsapp_otp_codes')
            ->where('otp_token', $token)
            ->where('expires_at', '>', now())
            ->first();

        if (! $row || ! hash_equals((string) $row->code, $otp)) {
            return null;
        }

        return (string) $row->whatsapp;
    }

    public static function markVerified(string $whatsapp): void
    {
        DB::table('whatsapp_otp_codes')->where('whatsapp', $whatsapp)->delete();

        DB::table('whatsapp_otp_verified')->updateOrInsert(
            ['whatsapp' => $whatsapp],
            [
                'expires_at' => now()->addMinutes(self::VERIFIED_TTL_MINUTES),
                'verified_at' => now(),
            ]
        );
    }

    public static function isVerified(string $whatsapp): bool
    {
        return DB::table('whatsapp_otp_verified')
            ->where('whatsapp', $whatsapp)
            ->where('expires_at', '>', now())
            ->exists();
    }

    public static function consumeVerified(string $whatsapp): void
    {
        DB::table('whatsapp_otp_verified')->where('whatsapp', $whatsapp)->delete();
    }

    public static function ttlSeconds(): int
    {
        return self::OTP_TTL_MINUTES * 60;
    }

    private static function generateToken(): string
    {
        return Str::random(40);
    }
}
