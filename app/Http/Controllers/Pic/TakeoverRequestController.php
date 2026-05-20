<?php

namespace App\Http\Controllers\Pic;

use App\Http\Controllers\Controller;
use App\Services\TicketTakeoverRequestService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TakeoverRequestController extends Controller
{
    public function __construct(private readonly TicketTakeoverRequestService $service)
    {
    }

    public function store(Request $request, string $ticketId)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
        ]);

        $req = $this->service->request($user, $ticketId, (string) $validated['reason']);

        return response()->json([
            'data' => [
                'id' => $req->id,
                'status' => $req->status,
                'reason' => $req->reason,
                'requested_by' => $req->requested_by,
                'created_at' => optional($req->created_at)->toISOString(),
            ],
        ], 201);
    }

    public function cancel(Request $request, string $ticketId)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        $req = $this->service->cancel($user, $ticketId);

        return response()->json([
            'data' => [
                'id' => $req->id,
                'status' => $req->status,
                'closed_at' => optional($req->closed_at)->toISOString(),
            ],
        ]);
    }
}

