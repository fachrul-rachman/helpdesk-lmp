<?php

namespace App\Http\Controllers\Spv;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PicLookupController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            throw new HttpException(401, 'Token tidak valid.');
        }
        if ((string) ($user->role ?? '') !== 'spv') {
            throw new HttpException(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        $validated = $request->validate([
            'division_id' => ['required', 'uuid'],
        ]);

        $pics = User::query()
            ->where('role', 'pic')
            ->where('is_active', true)
            ->inDivision((string) $validated['division_id'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'phone_number' => $u->phone_number,
            ])
            ->values();

        return response()->json(['data' => $pics]);
    }
}
