<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Admin\Actions\AdminLoginAction;
use App\Domain\Admin\Actions\CreateAdminAction;
use App\Domain\Admin\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends \App\Http\Controllers\Controller
{
    public function login(Request $request, AdminLoginAction $action): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        return response()->json($action->execute($credentials));
    }

    public function listAdmins(): JsonResponse
    {
        $admins = Admin::all(['id', 'name', 'email', 'created_at']);
        return response()->json(['admins' => $admins]);
    }

    public function createAdmin(Request $request, CreateAdminAction $action): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255',
            'password' => 'required|string|min:6',
        ]);

        $admin = $action->execute($data);

        return response()->json([
            'message' => 'Admin successfully created.',
            'admin'   => [
                'id'    => $admin->id,
                'name'  => $admin->name,
                'email' => $admin->email,
            ],
        ], 201);
    }
}
