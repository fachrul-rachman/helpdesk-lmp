<?php

namespace App\Http\Controllers\Spv;

use App\Http\Controllers\Controller;
use App\Http\Requests\Spv\TicketReportRequest;
use App\Services\TicketReportService;

class TicketReportController extends Controller
{
    public function __construct(private readonly TicketReportService $reports) {}

    public function index(TicketReportRequest $request)
    {
        return response()->json($this->reports->report($request->validated()));
    }

    public function tickets(TicketReportRequest $request)
    {
        return response()->json($this->reports->tickets($request->validated()));
    }

    public function reviews(TicketReportRequest $request)
    {
        return response()->json($this->reports->reviews($request->validated()));
    }
}
