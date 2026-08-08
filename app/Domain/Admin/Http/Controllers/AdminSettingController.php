<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Admin\Models\AdminSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettingController extends \App\Http\Controllers\Controller
{
    /**
     * Default value untuk setiap setting yang dikenal.
     */
    private const DEFAULTS = [
        'bypass_npwp_verification' => false,
    ];

    /**
     * GET /api/admin/settings
     */
    public function index(): JsonResponse
    {
        $fromDb = AdminSetting::allAsArray();

        // Merge dengan defaults supaya key selalu ada meski belum pernah di-set
        $settings = array_merge(self::DEFAULTS, $fromDb);

        return response()->json(['settings' => $settings]);
    }

    /**
     * POST /api/admin/settings
     * Body: { "bypass_npwp_verification": true }
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bypass_npwp_verification' => ['sometimes', 'boolean'],
        ]);

        foreach ($data as $key => $value) {
            AdminSetting::set($key, $value);
        }

        return response()->json([
            'message'  => 'Settings updated successfully.',
            'settings' => array_merge(self::DEFAULTS, AdminSetting::allAsArray()),
        ]);
    }
}
