<?php

namespace App\Http\Controllers;

use App\Models\Division;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DivisionController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        $divisions = Division::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Division $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'is_active' => (bool) $d->is_active,
                'is_fallback' => (bool) $d->is_fallback,
            ])
            ->values();

        return response()->json(['data' => $divisions]);
    }
}

