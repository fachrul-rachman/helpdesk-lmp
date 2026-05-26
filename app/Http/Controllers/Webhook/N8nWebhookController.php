<?php

namespace App\Http\Controllers\Webhook;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Message;
use App\Services\TicketService;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class N8nWebhookController extends Controller
{
    public function __construct(private readonly TicketService $ticketService)
    {
    }

    public function handle(Request $request)
    {
        $secret = (string) $request->header('X-N8N-Secret', '');
        $expected = (string) (env('N8N_INCOMING_SECRET', '') ?: env('N8N_SECRET', ''));
        if ($secret === '' || $expected === '' || $secret !== $expected) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $event = (string) $request->input('event', '');

        try {
            return match ($event) {
                'ticket.create' => $this->handleTicketCreate($request),
                'message.reply' => $this->handleMessageReply($request),
                'ticket.reopen_from_on_progress' => $this->handleReopenFromOnProgress($request),
                'system.error' => $this->handleSystemError($request),
                default => response()->json(['success' => false, 'message' => 'Payload tidak valid: event tidak dikenal.'], 422),
            };
        } catch (HttpException $e) {
            $code = $e->getStatusCode();
            if ($code === 401 || $code === 422) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], $code);
            }

            Log::error('n8n.webhook.error', ['message' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan pada server.'], 200);
        } catch (\Throwable $e) {
            Log::error('n8n.webhook.error', ['message' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan pada server.'], 200);
        }
    }

    private function handleTicketCreate(Request $request)
    {
        $ticket = $this->ticketService->createFromN8n($request->all());

        $aiReply = $request->input('ai_reply.message');
        if (is_string($aiReply) && $aiReply !== '') {
            $customer = $ticket->customer;
            $this->ticketService->sendTextToCustomer($customer->phone_number, $aiReply);

            $message = Message::create([
                'ticket_id' => $ticket->id,
                'customer_id' => $customer->id,
                'sender_type' => 'ai',
                'sender_id' => null,
                'content' => $aiReply,
                'wa_message_id' => null,
                'created_at' => now(),
            ]);

            event(new MessageSent($this->ticketService->formatMessage($message)));
        }

        // Konfirmasi pembuatan ticket ke customer (pesan sistem).
        $ticket->loadMissing(['customer' => fn ($q) => $q->withTrashed(), 'division']);
        $customer = $ticket->customer;
        if ($customer && $customer->phone_number) {
            $divisionName = (string) optional($ticket->division)->name;
            $divisionPart = $divisionName !== '' ? " Tim {$divisionName} kami" : ' Tim kami';

            $subject = (string) ($ticket->subject ?? '');
            $notes = (string) ($ticket->notes ?? '');
            $detail = trim($notes !== '' ? $notes : $subject);

            $detailPart = $detail !== '' ? " terkait {$detail}." : '.';

            $text =
                "Tiket dengan nomor #{$ticket->ticket_number} telah berhasil dibuat."
                .$divisionPart
                ." akan segera menindaklanjuti{$detailPart} Terima kasih atas kesabarannya.";

            $this->ticketService->sendTextToCustomer($customer->phone_number, $text);

            $message = Message::create([
                'ticket_id' => $ticket->id,
                'customer_id' => $customer->id,
                'sender_type' => 'system',
                'sender_id' => null,
                'content' => $text,
                'wa_message_id' => null,
                'created_at' => now(),
            ]);

            event(new MessageSent($this->ticketService->formatMessage($message)));
        }

        return response()->json(['success' => true, 'ticket_id' => $ticket->id], 200);
    }

    private function handleMessageReply(Request $request)
    {
        $customerPhone = PhoneNumber::normalize((string) $request->input('customer_phone_number', ''));
        $aiReply = (string) ($request->input('ai_reply.message', '') ?: $request->input('message.content', '') ?: $request->input('message', ''));

        $payload = $request->all();
        if (!isset($payload['ai_reply']) || !is_array($payload['ai_reply'])) {
            $payload['ai_reply'] = [];
        }
        if (is_array($payload['ai_reply']) && (!isset($payload['ai_reply']['message']) || (string) $payload['ai_reply']['message'] === '')) {
            $payload['ai_reply']['message'] = $aiReply;
        }

        $this->ticketService->saveAiReplyWithoutTicket($payload);

        /** @var Customer|null $customer */
        $customer = Customer::query()->where('phone_number', $customerPhone)->first();
        if ($customer && $aiReply !== '') {
            $this->ticketService->sendTextToCustomer($customer->phone_number, $aiReply);
        }

        return response()->json(['success' => true], 200);
    }

    private function handleReopenFromOnProgress(Request $request)
    {
        $ticketId = (string) $request->input('ticket_id', '');
        if ($ticketId === '') {
            throw new HttpException(422, 'Payload tidak valid: ticket_id wajib diisi.');
        }

        $ticket = $this->ticketService->reopenFromOnProgress($ticketId);

        $aiReply = (string) $request->input('ai_reply.message', '');
        if ($aiReply !== '') {
            $customer = $ticket->customer;
            $this->ticketService->sendTextToCustomer($customer->phone_number, $aiReply);

            Message::create([
                'ticket_id' => $ticket->id,
                'customer_id' => $customer->id,
                'sender_type' => 'ai',
                'sender_id' => null,
                'content' => $aiReply,
                'wa_message_id' => null,
                'created_at' => now(),
            ]);
        }

        return response()->json(['success' => true], 200);
    }

    private function handleSystemError(Request $request)
    {
        $customerPhone = PhoneNumber::normalize((string) $request->input('customer_phone_number', ''));
        if ($customerPhone === '') {
            throw new HttpException(422, 'Payload tidak valid: customer_phone_number wajib diisi.');
        }

        $customer = Customer::query()->where('phone_number', $customerPhone)->first();
        $name = $customer?->name ?? '';

        $this->ticketService->sendSystemErrorTemplate($customerPhone, $name);

        return response()->json(['success' => true], 200);
    }
}
