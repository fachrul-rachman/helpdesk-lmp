<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'phone_number' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [
            'phone_number.required' => 'Nomor HP wajib diisi.',
            'password.required' => 'Password wajib diisi.',
        ]);

        try {
            return response()->json(
                $this->authService->login(
                    phoneNumber: $data['phone_number'],
                    password: $data['password'],
                    ipAddress: $request->ip(),
                ),
                200,
            );
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function refresh(Request $request)
    {
        $data = $request->validate([
            'refresh_token' => ['required', 'string'],
        ], [
            'refresh_token.required' => 'Refresh token wajib diisi.',
        ]);

        try {
            return response()->json($this->authService->refresh($data['refresh_token']), 200);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function logout(Request $request)
    {
        $data = $request->validate([
            'refresh_token' => ['required', 'string'],
        ], [
            'refresh_token.required' => 'Refresh token wajib diisi.',
        ]);

        try {
            $this->authService->logout(
                user: $request->user(),
                refreshToken: $data['refresh_token'],
                ipAddress: $request->ip(),
            );

            return response()->json(['message' => 'Berhasil logout.'], 200);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'current_password.required' => 'Password lama wajib diisi.',
            'new_password.required' => 'Password baru wajib diisi.',
            'new_password.min' => 'Password baru minimal 8 karakter.',
            'new_password.confirmed' => 'Konfirmasi password baru tidak sama.',
        ]);

        try {
            $this->authService->changePassword(
                user: $request->user(),
                currentPassword: $data['current_password'],
                newPassword: $data['new_password'],
                ipAddress: $request->ip(),
            );

            return response()->json(['message' => 'Password berhasil diubah.'], 200);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function forceLogout(Request $request, string $user_id)
    {
        try {
            $this->authService->forceLogout(
                actor: $request->user(),
                targetUserId: $user_id,
                ipAddress: $request->ip(),
            );

            return response()->json(['message' => 'Semua sesi user berhasil dihentikan.'], 200);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }
    }
}

