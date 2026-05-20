<?php

namespace App\Http\Controllers;

use App\Services\TicketService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TicketController extends Controller
{
    public function __construct(private readonly TicketService $ticketService)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        $validated = $request->validate([
            'status' => ['nullable', 'in:new,open,pending,on_progress,queue,solved,closed'],
            'division_id' => ['nullable', 'uuid'],
            'assigned_to' => ['nullable', 'uuid'],
            'priority' => ['nullable', 'in:low,medium,high'],
            'has_request' => ['nullable', 'boolean'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->ticketService->listTickets($user, $validated));
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        return response()->json([
            'data' => $this->ticketService->getTicketDetail($user, $id),
        ]);
    }

    public function updateStatus(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        $validated = $request->validate([
            'status' => ['required', 'in:open,pending,on_progress,solved'],
        ]);

        $ticket = $this->ticketService->changeStatus($user, $id, (string) $validated['status']);

        return response()->json([
            'data' => $this->ticketService->formatTicketDetail($ticket),
        ]);
    }

    public function updateNotes(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $ticket = $this->ticketService->updateNotes($user, $id, $validated['notes'] ?? null);

        return response()->json([
            'data' => $this->ticketService->formatTicketDetail($ticket),
        ]);
    }

    public function updateCustomerNotes(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $customer = $this->ticketService->updateCustomerNotes($user, $id, $validated['notes'] ?? null);

        return response()->json([
            'data' => $this->ticketService->formatCustomer($customer),
        ]);
    }

    public function messagesIndex(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        return response()->json([
            'data' => $this->ticketService->listMessages($user, $id),
        ]);
    }

    public function messagesStore(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:10000'],
            'message' => ['nullable', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => [
                'file',
                'max:16384', // KB = 16 MB
                'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/3gpp,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        ]);

        $content = (string) ($validated['content'] ?? $validated['message'] ?? '');
        if ($content === '' && empty($validated['attachments'])) {
            throw new HttpException(422, 'Pesan tidak boleh kosong.');
        }

        $message = $this->ticketService->createHumanMessageWithAttachments(
            $user,
            $id,
            $content,
            $request->file('attachments', []),
        );

        return response()->json([
            'data' => $this->ticketService->formatMessage($message),
        ], 201);
    }
}
