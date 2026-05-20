<?php

namespace App\Http\Controllers\Spv;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AnalyticsController extends Controller
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

        return response()->json($this->dashboardService->spvAnalytics());
    }
}

