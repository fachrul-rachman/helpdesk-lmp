<?php

namespace App\Http\Controllers\Spv;

use App\Http\Controllers\Controller;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TicketController extends Controller
{
    public function __construct(private readonly TicketService $ticketService)
    {
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        $validated = $request->validate([
            'customer_phone_number' => ['required', 'string', 'max:30'],
            'division_id' => ['required', 'uuid'],
            'subject' => ['required', 'string', 'max:500'],
            'priority' => ['required', 'in:low,medium,high'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $ticket = $this->ticketService->createManualTicket($user, $validated);

        return response()->json([
            'data' => $this->ticketService->formatTicketDetail($ticket),
        ], 201);
    }

    public function updatePriority(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        $validated = $request->validate([
            'priority' => ['required', 'in:low,medium,high'],
        ]);

        $ticket = $this->ticketService->changePriority($user, $id, (string) $validated['priority']);

        return response()->json([
            'data' => $this->ticketService->formatTicketDetail($ticket),
        ]);
    }

    public function assign(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        $validated = $request->validate([
            'user_id' => ['required', 'uuid'],
        ]);

        $ticket = $this->ticketService->assignToPic($user, $id, (string) $validated['user_id']);

        return response()->json([
            'data' => $this->ticketService->formatTicketDetail($ticket),
        ]);
    }

    public function changeDivision(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        $validated = $request->validate([
            'division_id' => ['required', 'uuid'],
            'assigned_to' => ['nullable', 'uuid'],
        ]);

        $ticket = $this->ticketService->changeDivision($user, $id, (string) $validated['division_id'], $validated['assigned_to'] ?? null);

        return response()->json([
            'data' => $this->ticketService->formatTicketDetail($ticket),
        ]);
    }

    public function customerTickets(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        return response()->json([
            'data' => $this->ticketService->listCustomerTickets($user, $id),
        ]);
    }
}

