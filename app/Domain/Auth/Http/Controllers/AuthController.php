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
 * Tanggung jawab: Mengelola permintaan autentikasi dan OTP.
 * Pola: Thin Controller.
 */
class AuthController extends \App\Http\Controllers\Controller
{
    /**
     * Mendaftarkan pengguna baru.
     */
    public function register(RegisterUserRequest $request, RegisterUserAction $action): JsonResponse
    {
        return response()->json($action->execute($request->validated()), 201);
    }

    /**
     * Melakukan login pengguna.
     */
    public function login(LoginUserRequest $request, LoginUserAction $action): JsonResponse
    {
        return response()->json($action->execute($request->validated()));
    }

    /**
     * Mengirimkan kode OTP melalui WhatsApp.
     */
    public function sendOtp(SendOtpRequest $request, SendOtpAction $action): JsonResponse
    {
        return response()->json($action->execute($request->input('whatsapp')));
    }

    /**
     * Memverifikasi kode OTP yang dikirimkan.
     */
    public function verifyOtp(VerifyOtpRequest $request, VerifyOtpAction $action): JsonResponse
    {
        return response()->json($action->execute($request->validated()));
    }
}
