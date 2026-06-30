<?php

namespace App\Services;

use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Ticket;
use App\Models\TicketSatisfactionReview;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\URL;

class NotificationService
{
    private const TEMPLATE_PENDING_REMINDER = 'cust_ticket_will_be_closed';

    private const TEMPLATE_SLA_FR_WARNING = 'sla_first_response_warning';

    private const TEMPLATE_SLA_FR_BREACHED = 'sla_first_response_breached';

    private const TEMPLATE_SLA_RESOLUTION_WARNING = 'sla_resolution_warning';

    private const TEMPLATE_SLA_RESOLUTION_BREACHED = 'sla_resolution_breached';

    private const TEMPLATE_TICKET_ASSIGNED = 'ticket_assigned_to_agent';

    private const TEMPLATE_TICKET_REOPENED = 'ticket_on_progress_opened';

    public function sendText(string $toPhone, string $message): void
    {
        SendWhatsAppMessageJob::dispatch([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $toPhone,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $message,
            ],
        ], [
            'to' => $toPhone,
            'type' => 'text',
        ]);
    }

    public function sendMedia(
        string $toPhone,
        string $mediaUrl,
        string $type,
        string $caption = '',
        string $filename = '',
    ): void {
        $type = strtolower($type);
        if (!in_array($type, ['image', 'video', 'document'], true)) {
            throw new \InvalidArgumentException('Tipe media tidak didukung.');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $toPhone,
            'type' => $type,
            $type => array_filter([
                'link' => $mediaUrl,
                'caption' => $caption !== '' ? $caption : null,
                'filename' => $type === 'document' && $filename !== '' ? $filename : null,
            ], fn ($v) => $v !== null),
        ];

        SendWhatsAppMessageJob::dispatch($payload, [
            'to' => $toPhone,
            'type' => $type,
        ]);
    }

    /**
     * @param  array<int, string>  $parameters Urutan harus sesuai {{1}}, {{2}}, dst.
     */
    public function sendTemplate(string $toPhone, string $templateName, array $parameters = []): void
    {
        $components = [];
        if ($parameters !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $text) => ['type' => 'text', 'text' => $text],
                    $parameters,
                ),
            ];
        }

        SendWhatsAppMessageJob::dispatch([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $toPhone,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => 'id'],
                'components' => $components,
            ],
        ], [
            'to' => $toPhone,
            'type' => 'template',
            'template' => $templateName,
        ]);
    }

    /**
     * Kirim template dengan URL button parameter (index 0).
     *
     * @param  array<int, string>  $bodyParameters
     */
    public function sendTemplateWithUrlButton(
        string $toPhone,
        string $templateName,
        array $bodyParameters,
        string $urlButtonParameter,
    ): void {
        $components = [];

        if ($bodyParameters !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $text) => ['type' => 'text', 'text' => $text],
                    $bodyParameters,
                ),
            ];
        }

        $components[] = [
            'type' => 'button',
            'sub_type' => 'url',
            'index' => '0',
            'parameters' => [
                ['type' => 'text', 'text' => $urlButtonParameter],
            ],
        ];

        SendWhatsAppMessageJob::dispatch([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $toPhone,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => 'id'],
                'components' => $components,
            ],
        ], [
            'to' => $toPhone,
            'type' => 'template',
            'template' => $templateName,
        ]);
    }

    public function markAsRead(string $waMessageId, string $typingType = 'text'): void
    {
        // Mark-as-read tidak wajib retry.
        $payload = [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $waMessageId,
        ];

        // Beberapa implementasi Cloud API mendukung typing_indicator saat mark-as-read.
        // Jika tidak didukung, Meta akan mengembalikan 4xx (no-retry) dan sistem tetap berjalan.
        $enabled = filter_var((string) env('META_WA_TYPING_INDICATOR_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
        if ($enabled) {
            $payload['typing_indicator'] = [
                'type' => in_array($typingType, ['text', 'audio', 'video', 'image', 'document'], true) ? $typingType : 'text',
            ];
        }

        SendWhatsAppMessageJob::dispatch($payload, [
            'type' => 'read',
            'wa_message_id' => $waMessageId,
        ], false);
    }

    public function sendPicNewTicket(User $pic, Ticket $ticket): void
    {
        $customerName = (string) optional($ticket->customer)->name;
        $divisionName = (string) optional($ticket->division)->name;

        $this->sendTemplate($pic->phone_number, 'pic_new_ticket', [
            $pic->name,
            (string) $ticket->subject,
            $customerName !== '' ? $customerName : 'Customer',
            ucfirst((string) $ticket->priority),
            $divisionName !== '' ? $divisionName : '-',
        ]);
    }

    public function sendTicketAssignedToAgent(User $agent, Ticket $ticket): void
    {
        $ticket->loadMissing(['customer' => fn ($q) => $q->withTrashed(), 'division']);

        $ticketNumber = trim((string) ($ticket->ticket_number ?? ''));
        if ($ticketNumber === '' && $ticket->ticket_seq) {
            $yearTwoDigits = optional($ticket->created_at)?->timezone('Asia/Jakarta')->format('y') ?? now('Asia/Jakarta')->format('y');
            $ticketNumber = 'T'.$yearTwoDigits.'-'.str_pad((string) $ticket->ticket_seq, 5, '0', STR_PAD_LEFT);
        }
        if ($ticketNumber === '') {
            $ticketNumber = '-';
        }

        $customerName = trim((string) optional($ticket->customer)->name);
        if ($customerName === '') {
            $customerName = 'Customer';
        }

        $summary = (string) ($ticket->notes ?? '');
        if (trim($summary) === '') {
            $summary = (string) ($ticket->subject ?? '');
        }
        $summary = trim($summary);
        if ($summary === '') {
            $summary = '-';
        }

        $title = trim((string) ($ticket->subject ?? ''));
        if ($title === '') {
            $title = '-';
        }

        // Hindari payload terlalu panjang untuk template Meta.
        if (mb_strlen($summary) > 500) {
            $summary = mb_substr($summary, 0, 497).'...';
        }

        // Template ini dibuat dengan parameter bernama (named parameters) di Meta, jadi setiap parameter
        // perlu menyertakan `parameter_name` agar tidak ditolak ("Parameter name is missing or empty").
        SendWhatsAppMessageJob::dispatch([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
                'to' => $agent->phone_number,
                'type' => 'template',
                'template' => [
                'name' => self::TEMPLATE_TICKET_ASSIGNED,
                    'language' => ['code' => 'id'],
                    'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $ticketNumber, 'parameter_name' => 'nomor_ticket'],
                            ['type' => 'text', 'text' => $customerName, 'parameter_name' => 'nama_customer'],
                            ['type' => 'text', 'text' => $title, 'parameter_name' => 'judul_ticket'],
                            ['type' => 'text', 'text' => $summary, 'parameter_name' => 'ringkasan'],
                        ],
                    ],
                ],
            ],
        ], [
            'to' => $agent->phone_number,
            'type' => 'template',
            'template' => self::TEMPLATE_TICKET_ASSIGNED,
        ]);
    }

    public function sendPicSlaFrWarning(User $pic, Ticket $ticket, int $remainingMinutes): void
    {
        $customerName = (string) optional($ticket->customer)->name;

        $this->sendTemplate($pic->phone_number, self::TEMPLATE_SLA_FR_WARNING, [
            $pic->name,
            (string) $ticket->subject,
            $customerName !== '' ? $customerName : 'Customer',
            (string) max(0, $remainingMinutes),
        ]);
    }

    public function sendPicSlaResolutionWarning(User $pic, Ticket $ticket, CarbonInterface $deadline): void
    {
        $customerName = (string) optional($ticket->customer)->name;

        $this->sendTemplate($pic->phone_number, self::TEMPLATE_SLA_RESOLUTION_WARNING, [
            $pic->name,
            (string) $ticket->subject,
            $customerName !== '' ? $customerName : 'Customer',
            $deadline->timezone('Asia/Jakarta')->translatedFormat('d M Y H:i'),
        ]);
    }

    public function sendPicTicketReopened(User $pic, Ticket $ticket): void
    {
        $customerName = (string) optional($ticket->customer)->name;

        $this->sendTemplate($pic->phone_number, self::TEMPLATE_TICKET_REOPENED, [
            $pic->name,
            (string) $ticket->subject,
            $customerName !== '' ? $customerName : 'Customer',
        ]);
    }

    public function sendSpvSlaFrOverdue(User $spv, Ticket $ticket): void
    {
        $customerName = (string) optional($ticket->customer)->name;
        $divisionName = (string) optional($ticket->division)->name;
        $picName = (string) optional($ticket->assignee)->name;

        $this->sendTemplate($spv->phone_number, self::TEMPLATE_SLA_FR_BREACHED, [
            (string) $ticket->subject,
            $customerName !== '' ? $customerName : 'Customer',
            $divisionName !== '' ? $divisionName : '-',
            $picName !== '' ? $picName : 'Belum di-assign',
        ]);
    }

    public function sendSpvSlaResolutionOverdue(User $spv, Ticket $ticket, string $lateHumanReadable): void
    {
        $customerName = (string) optional($ticket->customer)->name;
        $divisionName = (string) optional($ticket->division)->name;
        $picName = (string) optional($ticket->assignee)->name;

        $this->sendTemplate($spv->phone_number, self::TEMPLATE_SLA_RESOLUTION_BREACHED, [
            (string) $ticket->subject,
            $customerName !== '' ? $customerName : 'Customer',
            $divisionName !== '' ? $divisionName : '-',
            $picName !== '' ? $picName : 'Belum di-assign',
            $lateHumanReadable,
        ]);
    }

    public function sendCustomerPendingReminder(Ticket $ticket, int $remainingMinutes = 60): void
    {
        $customer = $ticket->customer;
        if (!$customer || !$customer->phone_number) {
            return;
        }

        $customerName = (string) ($customer->name ?? '');

        $this->sendTemplate($customer->phone_number, self::TEMPLATE_PENDING_REMINDER, [
            $customerName !== '' ? $customerName : 'Halo',
            (string) $ticket->subject,
            (string) max(0, $remainingMinutes),
        ]);
    }

    public function sendAiIntroduction(string $toPhone, string $customerName = ''): void
    {
        $this->sendTemplate($toPhone, 'ai_introduction', [
            $customerName !== '' ? $customerName : 'Halo',
        ]);
    }

    public function sendSystemError(string $toPhone, string $customerName = ''): void
    {
        $this->sendTemplate($toPhone, 'system_error', [
            $customerName !== '' ? $customerName : 'Halo',
        ]);
    }

    public function sendSatisfactionReview(Ticket $ticket): void
    {
        $ticket->loadMissing(['customer' => fn ($q) => $q->withTrashed()]);
        $customer = $ticket->customer;
        if (!$customer || !$customer->phone_number) {
            return;
        }

        $already = TicketSatisfactionReview::query()->where('ticket_id', $ticket->id)->exists();
        if ($already) {
            return;
        }

        // Meta template button URL sudah menyimpan base URL di dashboard Meta.
        // Kita hanya kirim variable {{1}} berupa query string: ?customer_id=...&expires=...&ticket_id=...&signature=...
        //
        // PENTING: Route ini memakai middleware `signed` (absolute). Jadi signature harus dibuat dari URL ABSOLUTE,
        // lalu kita kirim hanya bagian query string-nya untuk ditempelkan oleh Meta ke base URL.
        $signedAbsolute = URL::temporarySignedRoute(
            'review.satisfaction',
            now()->addDays(3),
            ['ticket_id' => $ticket->id, 'customer_id' => $ticket->customer_id],
        );

        $query = (string) (parse_url($signedAbsolute, PHP_URL_QUERY) ?? '');
        $queryString = $query !== '' ? ('?'.$query) : '';

        $this->sendTemplateWithUrlButton(
            $customer->phone_number,
            'satisfaction_review',
            [],
            $queryString,
        );
    }
}
