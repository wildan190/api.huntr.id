<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class OtpStore
{
    private const OTP_TTL_MINUTES = 10;

    private const VERIFIED_TTL_MINUTES = 15;

    public static function issue(string $whatsapp): string
    {
        $existing = DB::table('whatsapp_otp_codes')
            ->where('whatsapp', $whatsapp)
            ->where('expires_at', '>', now())
            ->value('code');

        if ($existing !== null && $existing !== '') {
            $otp = (string) $existing;

            DB::table('whatsapp_otp_codes')->updateOrInsert(
                ['whatsapp' => $whatsapp],
                [
                    'code' => $otp,
                    'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
                    'created_at' => now(),
                ]
            );

            return $otp;
        }

        $otp = (string) random_int(100000, 999999);

        DB::table('whatsapp_otp_codes')->updateOrInsert(
            ['whatsapp' => $whatsapp],
            [
                'code' => $otp,
                'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
                'created_at' => now(),
            ]
        );

        return $otp;
    }

    public static function hasPending(string $whatsapp): bool
    {
        return DB::table('whatsapp_otp_codes')
            ->where('whatsapp', $whatsapp)
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
}
