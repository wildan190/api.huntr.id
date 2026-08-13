<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Admin\Actions\AdminLoginAction;
use App\Domain\Admin\Actions\CreateAdminAction;
use App\Domain\Admin\Actions\DeleteUserAction;
use App\Domain\Admin\Actions\GetAdminUsersAction;
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

    /**
     * Get all users (global view) with search and pagination.
     */
    public function listUsers(Request $request, GetAdminUsersAction $action): JsonResponse
    {
        $result = $action->execute(
            $request->only('search'),
            (int) $request->query('per_page', 10)
        );

        return response()->json([
            'total' => $result['total'],
            'users' => $result['users'],
        ]);
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

    /**
     * Delete a user (only allowed if user has no company).
     */
    public function deleteUser(string $userId, DeleteUserAction $action): JsonResponse
    {
        try {
            $action->execute($userId);
            return response()->json(['message' => 'User berhasil dihapus.']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'User tidak ditemukan.'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
