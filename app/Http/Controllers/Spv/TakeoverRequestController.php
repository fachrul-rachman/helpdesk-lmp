<?php

namespace App\Http\Controllers\Spv;

use App\Http\Controllers\Controller;
use App\Services\TicketTakeoverRequestService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TakeoverRequestController extends Controller
{
    public function __construct(private readonly TicketTakeoverRequestService $service)
    {
    }

    public function approve(Request $request, string $ticketId)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        $req = $this->service->approve($user, $ticketId);

        return response()->json([
            'data' => [
                'id' => $req->id,
                'status' => $req->status,
                'approved_at' => optional($req->approved_at)->toISOString(),
            ],
        ]);
    }

    public function reject(Request $request, string $ticketId)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        $req = $this->service->reject($user, $ticketId);

        return response()->json([
            'data' => [
                'id' => $req->id,
                'status' => $req->status,
                'rejected_at' => optional($req->rejected_at)->toISOString(),
            ],
        ]);
    }

    public function close(Request $request, string $ticketId)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        $req = $this->service->close($user, $ticketId);

        return response()->json([
            'data' => [
                'id' => $req->id,
                'status' => $req->status,
                'closed_at' => optional($req->closed_at)->toISOString(),
            ],
        ]);
    }
}

