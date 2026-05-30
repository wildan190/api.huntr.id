<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Models\Admin;
use App\Domain\Company\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminController extends \App\Http\Controllers\Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $admin = Admin::where('email', $request->input('email'))->first();

        if (!$admin || !Hash::check($request->input('password'), $admin->password)) {
            return response()->json([
                'message' => 'Kredensial admin tidak valid.'
            ], 401);
        }

        return response()->json([
            'message' => 'Login admin berhasil.',
            'admin'   => [
                'id'    => $admin->id,
                'name'  => $admin->name,
                'email' => $admin->email,
            ],
            'token'   => 'admin_session_token_' . $admin->id,
        ]);
    }

    public function listCompanies(Request $request): JsonResponse
    {
        $perPage = $request->query('per_page', 10);
        $search = $request->query('search');
        $status = $request->query('status');

        $query = Company::with(['documents']);

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $companies = $query->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        // Get stats for all statuses
        $stats = [
            'total'    => Company::count(),
            'pending'  => Company::where('status', 'pending')->count(),
            'approved' => Company::where('status', 'approved')->count(),
            'rejected' => Company::where('status', 'rejected')->count(),
        ];

        return response()->json(array_merge($companies->toArray(), ['stats' => $stats]));
    }

    public function auditCompany(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:approve,decline',
            'notes'  => 'nullable|string',
        ]);

        $company = Company::findOrFail($id);
        $status  = $request->input('action') === 'approve' ? 'approved' : 'rejected';

        $company->update([
            'status'             => $status,
            'verification_notes' => $request->input('notes'),
        ]);

        return response()->json([
            'message' => 'Status perusahaan berhasil diperbarui.',
            'company' => $company->load('documents'),
        ]);
    }
}
