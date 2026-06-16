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
        $verifyPath = $docType === 'do'
            ? "/api/do/{$docId}/print"
            : "/api/basts/{$docId}/pdf";

        return json_encode([
            'platform' => 'huntr.id',
            'doc_type' => $docType,
            'doc_id' => $docId,
            'role' => $role,
            'signer' => $signerName,
            'signed_at' => $signedAt,
            'verify_url' => url($verifyPath),
        ], JSON_UNESCAPED_SLASHES);
    }
}
