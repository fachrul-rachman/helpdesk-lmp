<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserPasswordController extends Controller
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function resetPassword(Request $request, string $id)
    {
        $data = $request->validate([
            'new_password' => ['required', 'string', 'min:8'],
        ], [
            'new_password.required' => 'Password baru wajib diisi.',
            'new_password.min' => 'Password baru minimal 8 karakter.',
        ]);

        try {
            $this->authService->resetPassword(
                actor: $request->user(),
                targetUserId: $id,
                newPassword: $data['new_password'],
                ipAddress: $request->ip(),
            );

            return response()->json(['message' => 'Password berhasil direset.'], 200);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }
    }
}

