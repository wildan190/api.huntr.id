<?php

namespace App\Domain\Auth\Http\Controllers;

use App\Domain\Auth\Actions\RegisterUserAction;
use App\Domain\Auth\Actions\LoginUserAction;
use App\Domain\Auth\Actions\SendOtpAction;
use App\Domain\Auth\Actions\VerifyOtpAction;
use App\Domain\Auth\Http\Requests\RegisterUserRequest;
use App\Domain\Auth\Http\Requests\LoginUserRequest;
use App\Domain\Auth\Http\Requests\SendOtpRequest;
use App\Domain\Auth\Http\Requests\VerifyOtpRequest;
use Illuminate\Http\JsonResponse;

/**
 * AuthController
 * 
 * Responsibility: Manages authentication and OTP requests.
 * Pattern: Thin Controller.
 */
class AuthController extends \App\Http\Controllers\Controller
{
    /**
     * Register a new user.
     */
    public function register(RegisterUserRequest $request, RegisterUserAction $action): JsonResponse
    {
        return response()->json($action->execute($request->validated()), 201);
    }

    /**
     * Perform user login.
     */
    public function login(LoginUserRequest $request, LoginUserAction $action): JsonResponse
    {
        return response()->json($action->execute($request->validated()));
    }

    /**
     * Send OTP code via WhatsApp.
     */
    public function sendOtp(SendOtpRequest $request, SendOtpAction $action): JsonResponse
    {
        return response()->json($action->execute($request->input('whatsapp')));
    }

    /**
     * Verify the sent OTP code.
     */
    public function verifyOtp(VerifyOtpRequest $request, VerifyOtpAction $action): JsonResponse
    {
        return response()->json($action->execute($request->validated()));
    }
}
