<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketSatisfactionReview;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SatisfactionReviewController extends Controller
{
    public function show(Request $request)
    {
        $ticketId = (string) $request->query('ticket_id', '');
        $customerId = (string) $request->query('customer_id', '');

        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()
            ->with(['customer'])
            ->find($ticketId);

        if (!$ticket || (string) $ticket->customer_id !== $customerId) {
            return response()->view('review.invalid', [
                'title' => 'Link tidak valid',
                'message' => 'Link review tidak valid atau sudah tidak berlaku.',
            ], 404);
        }

        if (!in_array((string) $ticket->status, ['solved', 'closed'], true)) {
            return response()->view('review.invalid', [
                'title' => 'Belum dapat direview',
                'message' => 'Ticket ini belum ditutup, jadi belum bisa direview.',
            ], 422);
        }

        $already = TicketSatisfactionReview::query()->where('ticket_id', $ticket->id)->exists();
        if ($already) {
            return response()->view('review.invalid', [
                'title' => 'Review sudah terkirim',
                'message' => 'Review untuk ticket ini sudah pernah dikirim. Terima kasih.',
            ], 200);
        }

        return response()->view('review.satisfaction', [
            'ticket' => $ticket,
            'customer' => $ticket->customer,
        ]);
    }

    public function submit(Request $request, NotificationService $notificationService)
    {
        $ticketId = (string) $request->query('ticket_id', '');
        $customerId = (string) $request->query('customer_id', '');

        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()->with(['customer'])->find($ticketId);
        if (!$ticket || (string) $ticket->customer_id !== $customerId) {
            return response()->view('review.invalid', [
                'title' => 'Link tidak valid',
                'message' => 'Link review tidak valid atau sudah tidak berlaku.',
            ], 404);
        }

        if (!in_array((string) $ticket->status, ['solved', 'closed'], true)) {
            return response()->view('review.invalid', [
                'title' => 'Belum dapat direview',
                'message' => 'Ticket ini belum ditutup, jadi belum bisa direview.',
            ], 422);
        }

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ], [
            'rating.required' => 'Rating wajib diisi.',
            'rating.min' => 'Rating minimal 1 bintang.',
            'rating.max' => 'Rating maksimal 5 bintang.',
        ]);

        $now = CarbonImmutable::now();

        try {
            DB::transaction(function () use ($ticket, $customerId, $validated, $request, $now): void {
                TicketSatisfactionReview::create([
                    'ticket_id' => $ticket->id,
                    'customer_id' => $customerId,
                    'rating' => (int) $validated['rating'],
                    'feedback' => $validated['feedback'] ?? null,
                    'ip_address' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 255),
                    'submitted_at' => $now,
                ]);
            });
        } catch (\Throwable $e) {
            $already = TicketSatisfactionReview::query()->where('ticket_id', $ticket->id)->exists();
            if ($already) {
                return response()->view('review.invalid', [
                    'title' => 'Review sudah terkirim',
                    'message' => 'Review untuk ticket ini sudah pernah dikirim. Terima kasih.',
                ], 200);
            }

            throw $e;
        }

        /** @var Customer|null $customer */
        $customer = $ticket->customer;
        if ($customer && $customer->phone_number) {
            $notificationService->sendText(
                $customer->phone_number,
                "Terima kasih atas apresiasi dan penilaian Anda.\nKami senang dapat membantu dan akan terus memberikan pelayanan terbaik untuk Anda.",
            );
        }

        return response()->view('review.thanks', [
            'ticket' => $ticket,
        ]);
    }
}

