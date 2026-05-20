<?php

namespace App\Http\Controllers\Spv;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ConversationController extends Controller
{
    public function __construct(private readonly DashboardService $dashboardService)
    {
    }

    public function __invoke(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'has_ticket' => ['nullable'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->dashboardService->spvConversations($validated));
    }
}

