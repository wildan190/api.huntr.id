<?php

namespace App\Domain\Communication\Http\Controllers;

use App\Domain\Communication\Actions\RefreshWhatsAppTokenAction;
use Illuminate\Http\JsonResponse;

class CommunicationController extends \App\Http\Controllers\Controller
{
    public function refreshWhatsAppToken(RefreshWhatsAppTokenAction $action): JsonResponse
    {
        $result = $action->execute();

        if (! $result['ok']) {
            return response()->json([
                'message' => 'Gagal menyegarkan token WhatsApp.',
                'detail' => $result['detail'] ?? 'unknown',
            ], 500);
        }

        return response()->json([
            'message' => 'Token WhatsApp berhasil disegarkan.',
            'expires_in' => $result['expires_in'] ?? 86400,
        ]);
    }
}
