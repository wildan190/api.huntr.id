<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Actions\SwitchUserRoleAction;
use App\Domain\Auth\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RoleSwitchController extends Controller
{
    /**
     * Switch the authenticated user's role (only in local environment).
     */
    public function switch(Request $request, SwitchUserRoleAction $action): JsonResponse
    {
        if (app()->environment() !== 'local') {
            return response()->json([
                'message' => 'Role switching is only allowed in local environment'
            ], 403);
        }

        $request->validate([
            'role' => 'required|string|in:super-admin,admin,manager,staff,buyer,finance'
        ]);

        /** @var User $user */
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'User not authenticated'], 401);
        }

        try {
            $userData = $action->execute($user, $request->role);
        } catch (NotFoundHttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'requested_role' => $request->role,
            ], 404);
        } catch (HttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => $e->getPrevious()?->getMessage(),
            ], $e->getStatusCode());
        }

        return response()->json([
            'message' => 'Role switched successfully. Role will be active for all subsequent requests.',
            'user' => $userData,
        ]);
    }
}
