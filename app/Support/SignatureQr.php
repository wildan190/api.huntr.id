<?php

namespace App\Support;

class SignatureQr
{
    public static function imageUrl(string $payload): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&margin=8&data=' . urlencode($payload);
    }

    public static function payload(
        string $docType,
        string $docId,
        string $role,
        ?string $signerName,
        ?string $signedAt
    ): string {
        // QR always points to the public Trust/Verify page
        $verifyUrl = rtrim(config('app.url', env('APP_URL', 'https://app.huntr.id')), '/');
        $frontendUrl = rtrim(env('VITE_APP_URL', str_replace('api.', 'app.', $verifyUrl)), '/');

        $queryString = http_build_query([
            'type' => $docType,
            'id'   => $docId,
            'role' => $role,
        ]);

        return json_encode([
            'platform'   => 'huntr.id',
            'doc_type'   => $docType,
            'doc_id'     => $docId,
            'role'       => $role,
            'signer'     => $signerName,
            'signed_at'  => $signedAt,
            'verify_url' => "{$frontendUrl}/verify?{$queryString}",
        ], JSON_UNESCAPED_SLASHES);
    }
}
